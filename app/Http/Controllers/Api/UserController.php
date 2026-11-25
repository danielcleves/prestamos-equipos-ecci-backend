<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\RespuestaPaginada;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreUserRequest;
use App\Http\Requests\Api\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    use RespuestaPaginada;

    public function index(Request $request): JsonResponse
    {
        // 'sanciones' se carga acotada a las vigentes (RN-04): UserResource
        // expone 'sancion_activa' en cada fila y sin esto sería una consulta
        // por usuario del listado.
        $usuarios = User::with([
            'roles',
            'sanciones' => fn ($query) => $query->vigentes(),
            // RN-01: UserResource lista las capacitaciones de cada fila.
            'categoriasCapacitadas',
        ])->orderBy('name');

        return $this->respuestaPaginada($usuarios, $request, UserResource::class);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();

        $usuario = DB::transaction(function () use ($data, $request) {
            $usuario = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                // Explicito en vez de confiar en el default de la columna: el
                // default de MySQL no se refleja en el modelo recien creado
                // hasta volver a consultarlo.
                'is_active' => true,
            ]);

            $usuario->assignRole($data['role']);

            // RN-01: por defecto, sin capacitaciones.
            $this->sincronizarCapacitaciones($usuario, $data['capacitaciones'] ?? [], $request->user());

            return $usuario;
        });

        return response()->json(['data' => new UserResource($usuario->load('categoriasCapacitadas'))], 201);
    }

    public function show(User $usuario): JsonResponse
    {
        return response()->json(['data' => new UserResource($usuario->load('categoriasCapacitadas'))]);
    }

    public function update(UpdateUserRequest $request, User $usuario): JsonResponse
    {
        $data = $request->validated();

        if (array_key_exists('name', $data)) {
            $usuario->name = $data['name'];
        }

        if (array_key_exists('email', $data)) {
            $usuario->email = $data['email'];
        }

        if (! empty($data['password'])) {
            $usuario->password = $data['password'];
        }

        DB::transaction(function () use ($usuario, $data, $request) {
            $usuario->save();

            if (array_key_exists('role', $data)) {
                $usuario->syncRoles([$data['role']]);
            }

            // RN-01: si no viene, las capacitaciones no se tocan.
            if (array_key_exists('capacitaciones', $data)) {
                $this->sincronizarCapacitaciones($usuario, $data['capacitaciones'], $request->user());
            }
        });

        return response()->json(['data' => new UserResource($usuario->load('categoriasCapacitadas'))]);
    }

    public function activar(User $usuario): JsonResponse
    {
        $usuario->update(['is_active' => true]);

        return response()->json(['data' => new UserResource($usuario)]);
    }

    public function desactivar(Request $request, User $usuario): JsonResponse
    {
        if ($usuario->is($request->user())) {
            // Sin este freno, un admin podria desactivarse a si mismo y
            // quedar sin forma de revertirlo (is_active solo se puede
            // cambiar desde este endpoint, que requiere ser admin activo).
            return response()->json([
                'message' => 'No puedes desactivar tu propia cuenta.',
            ], 422);
        }

        DB::transaction(function () use ($usuario) {
            $usuario->update(['is_active' => false]);

            // Sanctum no vence los tokens (sanctum.expiration = null): sin
            // revocarlos, la cuenta desactivada seguía operando con la sesión
            // que ya tenía abierta. El middleware 'activo' es la segunda
            // barrera por si quedara alguno.
            $usuario->tokens()->delete();
        });

        return response()->json(['data' => new UserResource($usuario)]);
    }

    /**
     * Deja al usuario capacitado exactamente en las categorías indicadas
     * (RN-01). Las capacitaciones que ya tenía conservan su fecha y quién
     * las otorgó; las nuevas quedan a nombre del admin autenticado.
     *
     * @param  array<int, int|string>  $categoriaIds
     */
    private function sincronizarCapacitaciones(User $usuario, array $categoriaIds, User $admin): void
    {
        $solicitadas = array_map('intval', $categoriaIds);
        $actuales = $usuario->categoriasCapacitadas()->pluck('categorias.id')->map(fn ($id) => (int) $id)->all();

        $retiradas = array_values(array_diff($actuales, $solicitadas));
        $nuevas = array_values(array_diff($solicitadas, $actuales));

        if ($retiradas !== []) {
            $usuario->categoriasCapacitadas()->detach($retiradas);
        }

        if ($nuevas !== []) {
            $usuario->categoriasCapacitadas()->attach($nuevas, ['otorgada_por' => $admin->id]);
        }
    }
}

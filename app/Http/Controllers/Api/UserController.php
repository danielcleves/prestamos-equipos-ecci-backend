<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreUserRequest;
use App\Http\Requests\Api\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->integer('per_page', 15), 100);

        $usuarios = User::with('roles')->orderBy('name')->paginate($perPage);

        return response()->json([
            'data' => UserResource::collection($usuarios),
            'meta' => [
                'current_page' => $usuarios->currentPage(),
                'last_page' => $usuarios->lastPage(),
                'per_page' => $usuarios->perPage(),
                'total' => $usuarios->total(),
            ],
        ]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();

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

        return response()->json(['data' => new UserResource($usuario)], 201);
    }

    public function show(User $usuario): JsonResponse
    {
        return response()->json(['data' => new UserResource($usuario)]);
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

        $usuario->save();

        if (array_key_exists('role', $data)) {
            $usuario->syncRoles([$data['role']]);
        }

        return response()->json(['data' => new UserResource($usuario)]);
    }

    public function activar(User $usuario): JsonResponse
    {
        $usuario->update(['is_active' => true]);

        return response()->json(['data' => new UserResource($usuario)]);
    }

    public function desactivar(Request $request, User $usuario): JsonResponse
    {
        if ($error = $this->rechazarSiEsUnoMismo($request, $usuario, 'desactivar')) {
            return $error;
        }

        $usuario->update(['is_active' => false]);

        return response()->json(['data' => new UserResource($usuario)]);
    }

    public function destroy(Request $request, User $usuario): JsonResponse
    {
        if ($error = $this->rechazarSiEsUnoMismo($request, $usuario, 'eliminar')) {
            return $error;
        }

        // Soft delete: un usuario eliminado puede tener historial asociado
        // mas adelante (prestamos, etc.) que no queremos perder ni dejar
        // huerfano. deleted_at tambien lo saca del login (User::where(...)
        // ya no lo encuentra) y de los listados de este controller.
        $usuario->delete();

        return response()->json(null, 204);
    }

    /**
     * Sin este freno, un admin podria desactivarse o eliminarse a si mismo y
     * quedar sin forma de revertirlo (ambas acciones requieren ser admin
     * activo).
     */
    private function rechazarSiEsUnoMismo(Request $request, User $usuario, string $accion): ?JsonResponse
    {
        if ($usuario->is($request->user())) {
            return response()->json([
                'message' => "No puedes {$accion} tu propia cuenta.",
            ], 422);
        }

        return null;
    }
}

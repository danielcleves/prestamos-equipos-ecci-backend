<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\IndexEquipoRequest;
use App\Http\Requests\Api\StoreEquipoRequest;
use App\Http\Requests\Api\UpdateEquipoEstadoRequest;
use App\Http\Resources\EquipoResource;
use App\Http\Resources\HistorialEstadoResource;
use App\Models\Equipo;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EquipoController extends Controller
{
    public function index(IndexEquipoRequest $request): JsonResponse
    {
        // max() evita que un per_page negativo o 0 llegue a paginate() (ver
        // el mismo arreglo en UserController::index, senalado en review).
        $perPage = max(1, min($request->integer('per_page', 15), 100));

        $query = Equipo::with('categoria')
            ->when(! ($request->user()?->esPersonalAdministrativo() ?? false), fn ($q) => $q->visiblesEnCatalogo())
            ->when($request->filled('categoria_id'), fn ($q) => $q->where('categoria_id', $request->integer('categoria_id')))
            ->when($request->filled('disponible'), fn ($q) => $request->boolean('disponible') ? $q->disponible() : $q->noDisponible())
            ->when($request->filled('buscar'), fn ($q) => $q->buscar($request->string('buscar')->toString()));

        $orden = $request->filled('ordenar') ? $request->input('ordenar') : 'nombre';

        match ($orden) {
            '-nombre' => $query->orderByDesc('nombre')->orderByDesc('id'),
            'disponibles_primero' => $query
                ->orderByRaw('CASE WHEN estado = ? THEN 0 ELSE 1 END', [Equipo::ESTADO_DISPONIBLE])
                ->orderBy('nombre')
                ->orderBy('id'),
            'recientes' => $query->orderByDesc('created_at')->orderByDesc('id'),
            default => $query->orderBy('nombre')->orderBy('id'),
        };

        $equipos = $query->paginate($perPage)->withQueryString();

        return response()->json([
            'data' => EquipoResource::collection($equipos),
            'meta' => [
                'current_page' => $equipos->currentPage(),
                'last_page' => $equipos->lastPage(),
                'per_page' => $equipos->perPage(),
                'total' => $equipos->total(),
            ],
        ]);
    }

    public function store(StoreEquipoRequest $request): JsonResponse
    {
        $data = $request->validated();

        $equipo = Equipo::create([
            ...$data,
            'estado' => Equipo::ESTADO_INICIAL,
        ]);

        return response()->json(['data' => new EquipoResource($equipo->load('categoria'))], 201);
    }

    public function show(Request $request, Equipo $equipo): JsonResponse
    {
        $this->asegurarVisibilidad($request->user(), $equipo);

        return response()->json(['data' => new EquipoResource($equipo->load('categoria'))]);
    }

    public function actualizarEstado(UpdateEquipoEstadoRequest $request, Equipo $equipo): JsonResponse
    {
        $equipo->update(['estado' => $request->validated('estado')]);

        return response()->json(['data' => new EquipoResource($equipo->load('categoria'))]);
    }

    public function historial(Request $request, Equipo $equipo): JsonResponse
    {
        $this->asegurarVisibilidad($request->user(), $equipo);

        $perPage = max(1, min($request->integer('per_page', 15), 100));

        $historial = $equipo->historialEstados()->with('usuario')->paginate($perPage);

        return response()->json([
            'data' => HistorialEstadoResource::collection($historial),
            'meta' => [
                'current_page' => $historial->currentPage(),
                'last_page' => $historial->lastPage(),
                'per_page' => $historial->perPage(),
                'total' => $historial->total(),
            ],
        ]);
    }

    private function asegurarVisibilidad(?User $user, Equipo $equipo): void
    {
        if (! ($user?->esPersonalAdministrativo() ?? false) && $equipo->isDadoDeBaja()) {
            throw (new ModelNotFoundException)->setModel(Equipo::class, [$equipo->id]);
        }
    }
}

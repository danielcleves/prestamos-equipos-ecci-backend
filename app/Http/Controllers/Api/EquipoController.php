<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreEquipoRequest;
use App\Http\Requests\Api\UpdateEquipoEstadoRequest;
use App\Http\Resources\EquipoResource;
use App\Http\Resources\HistorialEstadoResource;
use App\Models\Equipo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EquipoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // max() evita que un per_page negativo o 0 llegue a paginate() (ver
        // el mismo arreglo en UserController::index, senalado en review).
        $perPage = max(1, min($request->integer('per_page', 15), 100));

        $equipos = Equipo::with('categoria')
            ->when($request->user()?->hasRole('usuario'), fn ($query) => $query->visiblesEnCatalogo())
            ->orderBy('nombre')
            ->paginate($perPage);

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
        if ($request->user()?->hasRole('usuario') && $equipo->estado === 'dado_de_baja') {
            return response()->json(['message' => 'Equipo no encontrado.'], 404);
        }

        return response()->json(['data' => new EquipoResource($equipo->load('categoria'))]);
    }

    public function actualizarEstado(UpdateEquipoEstadoRequest $request, Equipo $equipo): JsonResponse
    {
        $equipo->update(['estado' => $request->validated('estado')]);

        return response()->json(['data' => new EquipoResource($equipo->load('categoria'))]);
    }

    public function historial(Request $request, Equipo $equipo): JsonResponse
    {
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
}

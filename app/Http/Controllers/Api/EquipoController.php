<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreEquipoRequest;
use App\Http\Resources\EquipoResource;
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

        $equipos = Equipo::with('categoria')->orderBy('nombre')->paginate($perPage);

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

    public function show(Equipo $equipo): JsonResponse
    {
        return response()->json(['data' => new EquipoResource($equipo->load('categoria'))]);
    }
}

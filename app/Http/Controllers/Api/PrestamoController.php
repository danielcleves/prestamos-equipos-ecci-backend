<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StorePrestamoRequest;
use App\Http\Resources\PrestamoResource;
use App\Models\Prestamo;
use App\Services\SolicitudPrestamoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PrestamoController extends Controller
{
    /**
     * Lista los préstamos según el rol:
     * - usuario común: únicamente sus propios préstamos.
     * - admin y encargado: todos los préstamos.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min($request->integer('per_page', 15), 100));

        $query = Prestamo::with(['equipo.categoria', 'solicitante', 'entregadoPor', 'recibidoPor']);

        if (! $request->user()->esPersonalAdministrativo()) {
            $query->where('usuario_id', $request->user()->id);
        }

        $prestamos = $query->latest('id')->paginate($perPage)->withQueryString();

        return response()->json([
            'data' => PrestamoResource::collection($prestamos),
            'meta' => [
                'current_page' => $prestamos->currentPage(),
                'last_page' => $prestamos->lastPage(),
                'per_page' => $prestamos->perPage(),
                'total' => $prestamos->total(),
            ],
        ]);
    }

    /**
     * Registra una nueva solicitud de préstamo.
     */
    public function store(StorePrestamoRequest $request, SolicitudPrestamoService $service): JsonResponse
    {
        $data = $request->safe()->only([
            'equipo_id',
            'motivo',
            'fecha_inicio',
            'fecha_devolucion_estimada',
        ]);

        $prestamo = $service->solicitar($request->user(), $data);

        return response()->json([
            'data' => new PrestamoResource($prestamo->load(['equipo.categoria', 'solicitante'])),
        ], 201);
    }

    /**
     * Consulta el detalle de un préstamo puntual.
     * Protegido por PrestamoPolicy: el solicitante solo puede ver el suyo; personal administrativo ve cualquiera.
     */
    public function show(Request $request, Prestamo $prestamo): JsonResponse
    {
        Gate::authorize('view', $prestamo);

        return response()->json([
            'data' => new PrestamoResource($prestamo->load([
                'equipo.categoria',
                'solicitante',
                'entregadoPor',
                'recibidoPor',
            ])),
        ]);
    }
}

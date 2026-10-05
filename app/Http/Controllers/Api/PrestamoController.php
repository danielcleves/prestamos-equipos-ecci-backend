<?php

namespace App\Http\Controllers\Api;

use App\Enums\EstadoPrestamo;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\DevolucionPrestamoRequest;
use App\Http\Requests\Api\EntregaPrestamoRequest;
use App\Http\Requests\Api\StorePrestamoRequest;
use App\Http\Resources\PrestamoResource;
use App\Models\Prestamo;
use App\Services\DevolucionPrestamoService;
use App\Services\EntregaPrestamoService;
use App\Services\SolicitudPrestamoService;
use Illuminate\Database\Eloquent\Builder;
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
     * HU-11: Lista los préstamos activos (en estado 'entregado') con soporte de búsqueda
     * por código o nombre de equipo, o nombre o correo del solicitante (solo personal autorizado).
     */
    public function activos(Request $request): JsonResponse
    {
        $perPage = max(1, min($request->integer('per_page', 15), 100));

        $query = Prestamo::with(['equipo.categoria', 'solicitante', 'entregadoPor'])
            ->where('estado', EstadoPrestamo::Entregado->value);

        if ($request->filled('buscar')) {
            $texto = $request->string('buscar')->toString();
            $escapado = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $texto);

            $query->where(function (Builder $q) use ($escapado) {
                $q->whereHas('equipo', function (Builder $eq) use ($escapado) {
                    $eq->whereRaw("codigo LIKE ? ESCAPE '!'", ["%{$escapado}%"])
                        ->orWhereRaw("nombre LIKE ? ESCAPE '!'", ["%{$escapado}%"]);
                })->orWhereHas('solicitante', function (Builder $usr) use ($escapado) {
                    $usr->whereRaw("name LIKE ? ESCAPE '!'", ["%{$escapado}%"])
                        ->orWhereRaw("email LIKE ? ESCAPE '!'", ["%{$escapado}%"]);
                });
            });
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

    /**
     * HU-09: Registra la entrega del equipo al solicitante (solo personal autorizado).
     */
    public function entrega(
        EntregaPrestamoRequest $request,
        Prestamo $prestamo,
        EntregaPrestamoService $service
    ): JsonResponse {
        $prestamoActualizado = $service->registrarEntrega(
            $prestamo,
            $request->user(),
            $request->validated()
        );

        return response()->json([
            'data' => new PrestamoResource($prestamoActualizado->load([
                'equipo.categoria',
                'solicitante',
                'entregadoPor',
            ])),
        ]);
    }

    /**
     * HU-11: Registra la devolución del equipo prestado (solo personal autorizado).
     */
    public function devolucion(
        DevolucionPrestamoRequest $request,
        Prestamo $prestamo,
        DevolucionPrestamoService $service
    ): JsonResponse {
        $prestamoActualizado = $service->registrarDevolucion(
            $prestamo,
            $request->user(),
            $request->validated()
        );

        return response()->json([
            'data' => new PrestamoResource($prestamoActualizado->load([
                'equipo.categoria',
                'solicitante',
                'entregadoPor',
                'recibidoPor',
            ])),
        ]);
    }
}

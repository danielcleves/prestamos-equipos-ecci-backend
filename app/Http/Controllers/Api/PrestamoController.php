<?php

namespace App\Http\Controllers\Api;

use App\Enums\EstadoPrestamo;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\DevolucionPrestamoRequest;
use App\Http\Requests\Api\EntregaPrestamoRequest;
use App\Http\Requests\Api\StorePrestamoRequest;
use App\Http\Resources\PrestamoResource;
use App\Models\Prestamo;
use App\Models\User;
use App\Services\AprobacionPrestamoService;
use App\Services\DevolucionPrestamoService;
use App\Services\EntregaPrestamoService;
use App\Services\SolicitudPrestamoService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        $prestamo = $service->solicitar($request->user(), $request->validated());

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
        $this->asegurarVisibilidad($request->user(), $prestamo);

        return response()->json([
            'data' => new PrestamoResource($prestamo->load([
                'equipo.categoria',
                'solicitante',
                'gestionadoPor',
                'entregadoPor',
                'recibidoPor',
            ])),
        ]);
    }

    /**
     * HU-08: Aprueba una solicitud de préstamo (solo personal autorizado: admin y encargado).
     */
    public function aprobacion(
        Request $request,
        Prestamo $prestamo,
        AprobacionPrestamoService $service
    ): JsonResponse {
        $prestamoActualizado = $service->aprobar($prestamo, $request->user());

        return response()->json([
            'data' => new PrestamoResource($prestamoActualizado->load([
                'equipo.categoria',
                'solicitante',
                'gestionadoPor',
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

    /**
     * Asegura la visibilidad del préstamo según el rol:
     * El solicitante dueño puede ver su propio préstamo; el personal administrativo puede ver cualquiera.
     * Si un usuario no autorizado consulta un préstamo ajeno, se lanza ModelNotFoundException para responder
     * 404 (mismo criterio de visibilidad de EquipoController), evitando revelar la existencia del registro.
     */
    private function asegurarVisibilidad(?User $user, Prestamo $prestamo): void
    {
        if ($user === null || (! $user->esPersonalAdministrativo() && $prestamo->usuario_id !== $user->id)) {
            throw (new ModelNotFoundException)->setModel(Prestamo::class, [$prestamo->id]);
        }
    }
}

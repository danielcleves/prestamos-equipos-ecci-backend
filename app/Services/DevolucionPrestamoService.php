<?php

namespace App\Services;

use App\Enums\CondicionEquipo;
use App\Enums\EstadoPrestamo;
use App\Models\Equipo;
use App\Models\Prestamo;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DevolucionPrestamoService
{
    public function __construct(
        protected TransicionPrestamoService $transicionService
    ) {}

    /**
     * Registra la devolución de un equipo prestado.
     * Orquesta dentro de una transacción el bloqueo estricto (primero equipo, luego préstamo)
     * y la actualización del estado del equipo a 'disponible' o 'mantenimiento'.
     *
     * @param  array{
     *     fecha_devolucion_real?: string|null,
     *     condicion_devolucion: CondicionEquipo|string,
     *     observaciones?: string|null
     * }  $datos
     *
     * @throws ValidationException
     */
    public function registrarDevolucion(Prestamo $prestamo, User $personal, array $datos): Prestamo
    {
        return DB::transaction(function () use ($prestamo, $personal, $datos) {
            // Orden de bloqueo para prevenir interbloqueos:
            // 1. Primero se bloquea el equipo con lockForUpdate()
            $equipo = Equipo::whereKey($prestamo->equipo_id)->lockForUpdate()->firstOrFail();

            $condicion = $datos['condicion_devolucion'] instanceof CondicionEquipo
                ? $datos['condicion_devolucion']
                : CondicionEquipo::from($datos['condicion_devolucion']);

            $fechaDevolucion = isset($datos['fecha_devolucion_real'])
                ? Carbon::parse($datos['fecha_devolucion_real'])
                : now();

            // Validación de coherencia temporal: la devolución no puede preceder a la entrega
            if ($prestamo->fecha_entrega_real && $fechaDevolucion->lt($prestamo->fecha_entrega_real)) {
                throw ValidationException::withMessages([
                    'fecha_devolucion_real' => 'La fecha de devolución real no puede ser anterior a la fecha de entrega real.',
                ]);
            }

            // 2. Transición del préstamo (TransicionPrestamoService bloquea el préstamo en segundo lugar)
            // Se debe usar la instancia fresca retornada por el servicio
            $prestamoActualizado = $this->transicionService->transicionar(
                $prestamo,
                EstadoPrestamo::Devuelto,
                [
                    'actor' => $personal,
                    'fecha' => $fechaDevolucion,
                    'condicion' => $condicion,
                    'observaciones' => $datos['observaciones'] ?? null,
                ]
            );

            // 3. Actualización de estado del equipo:
            // Si el estado es 'bueno', regresa a 'disponible'; en caso de daños o necesidad de mantenimiento, pasa a 'mantenimiento'.
            // EquipoObserver registra automáticamente la trazabilidad en historial_estados.
            $nuevoEstadoEquipo = ($condicion === CondicionEquipo::Bueno)
                ? Equipo::ESTADO_DISPONIBLE
                : Equipo::ESTADO_MANTENIMIENTO;

            $equipo->update(['estado' => $nuevoEstadoEquipo]);

            return $prestamoActualizado;
        });
    }
}

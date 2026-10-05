<?php

namespace App\Services;

use App\Enums\CondicionEquipo;
use App\Enums\EstadoPrestamo;
use App\Models\Equipo;
use App\Models\Prestamo;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EntregaPrestamoService
{
    public function __construct(
        protected TransicionPrestamoService $transicionService
    ) {}

    /**
     * Registra la entrega del equipo prestado.
     * Orquesta dentro de una transacción el bloqueo estricto (primero equipo, luego préstamo)
     * y la actualización del estado del equipo a 'en_prestamo'.
     *
     * @param  array{
     *     fecha_entrega_real?: string|null,
     *     condicion_entrega?: CondicionEquipo|string|null,
     *     observaciones?: string|null
     * }  $datos
     *
     * @throws ValidationException
     */
    public function registrarEntrega(Prestamo $prestamo, User $personal, array $datos = []): Prestamo
    {
        return DB::transaction(function () use ($prestamo, $personal, $datos) {
            // Orden de bloqueo para prevenir interbloqueos:
            // 1. Primero se bloquea el equipo con lockForUpdate()
            $equipo = Equipo::whereKey($prestamo->equipo_id)->lockForUpdate()->firstOrFail();

            if (! $equipo->isDisponible()) {
                throw ValidationException::withMessages([
                    'equipo' => 'El equipo no se encuentra disponible para registrar su entrega.',
                ]);
            }

            // Normalización de la condición de entrega (por defecto 'bueno')
            $condicion = isset($datos['condicion_entrega'])
                ? ($datos['condicion_entrega'] instanceof CondicionEquipo
                    ? $datos['condicion_entrega']
                    : CondicionEquipo::from($datos['condicion_entrega']))
                : CondicionEquipo::Bueno;

            // 2. Transición del préstamo (TransicionPrestamoService bloquea el préstamo en segundo lugar)
            // Se debe usar la instancia fresca retornada por el servicio
            $prestamoActualizado = $this->transicionService->transicionar(
                $prestamo,
                EstadoPrestamo::Entregado,
                [
                    'actor' => $personal,
                    'fecha' => $datos['fecha_entrega_real'] ?? now(),
                    'condicion' => $condicion,
                    'observaciones' => $datos['observaciones'] ?? null,
                ]
            );

            // 3. El equipo pasa a en_prestamo (EquipoObserver registra la fila en historial_estados)
            $equipo->update(['estado' => Equipo::ESTADO_EN_PRESTAMO]);

            return $prestamoActualizado;
        });
    }
}

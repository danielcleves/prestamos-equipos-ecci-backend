<?php

namespace App\Services;

use App\Enums\EstadoPrestamo;
use App\Models\Equipo;
use App\Models\Prestamo;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AprobacionPrestamoService
{
    public function __construct(
        protected TransicionPrestamoService $transicionService
    ) {}

    /**
     * Aprueba una solicitud de préstamo respetando el orden de bloqueo: equipo -> préstamo.
     * La aprobación NO altera el estado del equipo físico (eso ocurre durante la entrega).
     *
     * @throws ValidationException
     */
    public function aprobar(Prestamo $prestamo, User $personal): Prestamo
    {
        return DB::transaction(function () use ($prestamo, $personal) {
            // Orden de bloqueo único en todo el módulo para evitar interbloqueos: usuario -> equipo -> préstamo.
            // 1. Primero se bloquea el equipo con lockForUpdate() para verificar disponibilidad física con datos frescos.
            $equipo = Equipo::whereKey($prestamo->equipo_id)->lockForUpdate()->firstOrFail();

            // 2. El equipo debe cumplir Equipo::isDisponible()
            if (! $equipo->isDisponible()) {
                if ($equipo->isDadoDeBaja()) {
                    throw ValidationException::withMessages([
                        'equipo' => 'El equipo seleccionado ha sido dado de baja y no puede ser prestado.',
                    ]);
                }

                if ($equipo->estado === Equipo::ESTADO_MANTENIMIENTO) {
                    throw ValidationException::withMessages([
                        'equipo' => 'El equipo seleccionado se encuentra en mantenimiento y no está disponible para préstamo.',
                    ]);
                }

                if ($equipo->estado === Equipo::ESTADO_EN_PRESTAMO) {
                    throw ValidationException::withMessages([
                        'equipo' => 'El equipo seleccionado ya se encuentra en préstamo.',
                    ]);
                }

                throw ValidationException::withMessages([
                    'equipo' => 'El equipo seleccionado no se encuentra disponible para préstamo.',
                ]);
            }

            // 3. Transición del préstamo: TransicionPrestamoService bloquea el préstamo en segundo lugar
            // con lockForUpdate() y valida sobre datos frescos que su estado actual sea 'solicitado'.
            return $this->transicionService->transicionar(
                $prestamo,
                EstadoPrestamo::Aprobado,
                [
                    'actor' => $personal,
                    'fecha' => now(),
                ]
            );
        });
    }
}

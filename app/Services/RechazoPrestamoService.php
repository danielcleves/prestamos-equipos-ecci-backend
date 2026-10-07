<?php

namespace App\Services;

use App\Enums\EstadoPrestamo;
use App\Models\Prestamo;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class RechazoPrestamoService
{
    public function __construct(
        protected TransicionPrestamoService $transicionService
    ) {}

    /**
     * Rechaza una solicitud de préstamo en estado 'solicitado'.
     *
     * Nota sobre el orden de bloqueo: A diferencia de la entrega, devolución y aprobación,
     * el rechazo de una solicitud no altera ni consulta el estado del equipo físico; por lo tanto,
     * no requiere adquirir un bloqueo sobre la tabla 'equipos'. El bloqueo pesimista sobre el
     * préstamo ejecutado por TransicionPrestamoService es suficiente para garantizar atomicidad
     * y consistencia ante peticiones concurrentes.
     *
     * @throws ValidationException
     */
    public function rechazar(Prestamo $prestamo, User $personal, string $motivo): Prestamo
    {
        return $this->transicionService->transicionar(
            $prestamo,
            EstadoPrestamo::Rechazado,
            [
                'actor' => $personal,
                'motivo' => $motivo,
                'fecha' => now(),
            ]
        );
    }
}

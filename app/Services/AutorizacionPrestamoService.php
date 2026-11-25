<?php

namespace App\Services;

use App\Enums\EstadoPrestamo;
use App\Models\Prestamo;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * RN-03 — Autorización especial.
 *
 * Una solicitud sobre una categoría restringida (nivel_restriccion >= 1) se
 * crea con requiere_autorizacion = true y no puede aprobarse hasta que el
 * personal la autorice explícitamente.
 */
class AutorizacionPrestamoService
{
    /**
     * Marca la solicitud como autorizada.
     *
     * @throws ValidationException
     */
    public function autorizar(Prestamo $prestamo, User $personal): Prestamo
    {
        return DB::transaction(function () use ($prestamo, $personal) {
            // Orden de bloqueo del módulo: usuario -> equipo -> préstamo. La
            // autorización solo toca el préstamo, por eso no se bloquea el
            // equipo.
            $fresco = Prestamo::whereKey($prestamo->getKey())->lockForUpdate()->firstOrFail();

            if (! $fresco->requiere_autorizacion) {
                throw ValidationException::withMessages([
                    'prestamo' => 'Este préstamo no requiere autorización especial.',
                ]);
            }

            if ($fresco->estado !== EstadoPrestamo::Solicitado) {
                throw ValidationException::withMessages([
                    'estado' => "El préstamo está en estado {$fresco->estado->etiqueta()} y no admite autorización.",
                ]);
            }

            // Control de dos personas: quien pidió el equipo no puede firmar
            // su propia autorización especial, aunque sea personal.
            if ((int) $fresco->usuario_id === (int) $personal->id) {
                throw ValidationException::withMessages([
                    'prestamo' => 'No puedes autorizar tu propia solicitud: la autorización especial debe darla otro miembro del personal.',
                ]);
            }

            if ($fresco->autorizado_por !== null) {
                throw ValidationException::withMessages([
                    'prestamo' => 'Este préstamo ya fue autorizado.',
                ]);
            }

            $fresco->autorizado_por = $personal->id;
            $fresco->fecha_autorizacion = now();
            $fresco->save();

            return $fresco;
        });
    }
}

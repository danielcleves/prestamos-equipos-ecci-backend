<?php

namespace App\Services;

use App\Enums\CondicionEquipo;
use App\Enums\EstadoPrestamo;
use App\Models\Prestamo;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class TransicionPrestamoService
{
    /**
     * Valida y ejecuta la transición de estado de un préstamo dentro de una transacción.
     * Actualiza el estado y las columnas de fecha/actor correspondientes al destino.
     *
     * @param  array{
     *     actor?: User|null,
     *     fecha?: CarbonInterface|string|null,
     *     condicion?: CondicionEquipo|null,
     *     observaciones?: string|null
     * }  $datos
     *
     * @throws ValidationException
     * @throws InvalidArgumentException
     */
    public function transicionar(Prestamo $prestamo, EstadoPrestamo $destino, array $datos = []): Prestamo
    {
        return DB::transaction(function () use ($prestamo, $destino, $datos) {
            // Se recarga el préstamo con lockForUpdate() dentro de la transacción para obtener
            // datos frescos de la BD y evitar que dos peticiones simultáneas registren la misma entrega o devolución.
            // Nota: lockForUpdate() no bloquea en SQLite (los tests automatizados no cubren la carrera de concurrencia).
            $prestamoFresco = Prestamo::whereKey($prestamo->getKey())->lockForUpdate()->firstOrFail();

            $origen = $prestamoFresco->estado;

            if (! $origen->puedeTransicionarA($destino)) {
                throw ValidationException::withMessages([
                    'estado' => "El préstamo está en estado {$origen->etiqueta()} y no puede pasar a {$destino->etiqueta()}.",
                ]);
            }

            $prestamoFresco->estado = $destino;

            match ($destino) {
                EstadoPrestamo::Aprobado => $this->aplicarAprobacion($prestamoFresco, $datos),
                EstadoPrestamo::Rechazado => $this->aplicarRechazo($prestamoFresco, $datos),
                EstadoPrestamo::Cancelado => $this->aplicarCancelacion($prestamoFresco, $datos),
                EstadoPrestamo::Entregado => $this->aplicarEntrega($prestamoFresco, $datos),
                EstadoPrestamo::Devuelto => $this->aplicarDevolucion($prestamoFresco, $datos),
                default => null,
            };

            $prestamoFresco->save();

            return $prestamoFresco;
        });
    }

    private function aplicarAprobacion(Prestamo $prestamo, array $datos): void
    {
        $prestamo->fecha_aprobacion = $datos['fecha'] ?? now();
    }

    private function aplicarRechazo(Prestamo $prestamo, array $datos): void
    {
        // Espacio para trazabilidad de rechazo en HU correspondiente
    }

    private function aplicarCancelacion(Prestamo $prestamo, array $datos): void
    {
        // Espacio para trazabilidad de cancelación en HU correspondiente
    }

    private function aplicarEntrega(Prestamo $prestamo, array $datos): void
    {
        if (! isset($datos['actor'])) {
            throw new InvalidArgumentException('El actor que entrega el equipo es obligatorio.');
        }

        $prestamo->fecha_entrega_real = $datos['fecha'] ?? now();
        $prestamo->entregado_por = $datos['actor']->id;
        $prestamo->condicion_entrega = $datos['condicion'] ?? CondicionEquipo::Bueno;

        if (! empty($datos['observaciones'])) {
            $prestamo->agregarObservacion('Entrega', $datos['observaciones']);
        }
    }

    private function aplicarDevolucion(Prestamo $prestamo, array $datos): void
    {
        if (! isset($datos['actor'])) {
            throw new InvalidArgumentException('El actor que recibe el equipo es obligatorio.');
        }

        if (! isset($datos['condicion'])) {
            throw new InvalidArgumentException('La condición del equipo en la devolución es obligatoria.');
        }

        $prestamo->fecha_devolucion_real = $datos['fecha'] ?? now();
        $prestamo->recibido_por = $datos['actor']->id;
        $prestamo->condicion_devolucion = $datos['condicion'];

        if (! empty($datos['observaciones'])) {
            $prestamo->agregarObservacion('Devolución', $datos['observaciones']);
        }
    }
}

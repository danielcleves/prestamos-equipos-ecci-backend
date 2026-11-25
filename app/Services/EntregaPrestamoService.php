<?php

namespace App\Services;

use App\Enums\CondicionEquipo;
use App\Enums\EstadoPrestamo;
use App\Models\Equipo;
use App\Models\Prestamo;
use App\Models\User;
use App\Support\FechaNegocio;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EntregaPrestamoService
{
    public function __construct(
        protected TransicionPrestamoService $transicionService,
        protected ElegibilidadService $elegibilidadService
    ) {}

    /**
     * Registra la entrega del equipo prestado.
     * Orquesta dentro de una transacción el bloqueo estricto (primero equipo, luego préstamo)
     * y la actualización del estado del equipo a 'en_prestamo'.
     *
     * Antes de entregar se revalida la elegibilidad del solicitante (RN-01):
     * entre la aprobación y la entrega puede haber cambiado (sanción por mora,
     * cuenta desactivada, incidencia abierta), y el equipo no debe salir si ya
     * no corresponde.
     *
     * @param  array{
     *     fecha_entrega_real?: CarbonInterface|string|null,
     *     condicion_entrega?: CondicionEquipo|string|null,
     *     observaciones?: string|null
     * }  $datos
     *
     * @throws ValidationException
     */
    public function registrarEntrega(Prestamo $prestamo, User $personal, array $datos = []): Prestamo
    {
        return DB::transaction(function () use ($prestamo, $personal, $datos) {
            // Orden de bloqueo único en todo el módulo para prevenir interbloqueos: usuario -> equipo -> préstamo.
            // 0. El solicitante se bloquea primero para revalidar su elegibilidad con datos frescos.
            $solicitante = User::whereKey($prestamo->usuario_id)->lockForUpdate()->firstOrFail();

            // 1. Primero se bloquea el equipo con lockForUpdate() (el solicitante no participa directamente en la transacción).
            // Nota: lockForUpdate() no bloquea en SQLite (las carreras de concurrencia solo se reproducen en MySQL 8.0).
            $equipo = Equipo::whereKey($prestamo->equipo_id)->lockForUpdate()->firstOrFail();

            if (! $equipo->isDisponible()) {
                throw ValidationException::withMessages([
                    'equipo' => 'El equipo no se encuentra disponible para registrar su entrega.',
                ]);
            }

            // 2. Revalidación de elegibilidad al entregar (RN-01).
            if ($solicitante->is_active === false) {
                throw ValidationException::withMessages([
                    'usuario_id' => 'La cuenta del solicitante está desactivada; no se puede entregar el equipo.',
                ]);
            }

            try {
                $this->elegibilidadService->validar($solicitante, $equipo);
            } catch (ValidationException $e) {
                $motivo = $e->errors()['usuario_id'][0]
                    ?? 'el solicitante ya no es elegible para el préstamo.';

                throw ValidationException::withMessages([
                    'usuario_id' => "No se puede entregar el equipo: {$motivo}",
                ]);
            }

            // Normalización de la condición de entrega (por defecto 'bueno')
            $condicion = isset($datos['condicion_entrega'])
                ? ($datos['condicion_entrega'] instanceof CondicionEquipo
                    ? $datos['condicion_entrega']
                    : CondicionEquipo::from($datos['condicion_entrega']))
                : CondicionEquipo::Bueno;

            $fechaEntrega = isset($datos['fecha_entrega_real']) && $datos['fecha_entrega_real'] !== null
                ? ($datos['fecha_entrega_real'] instanceof CarbonInterface
                    ? $datos['fecha_entrega_real']
                    : FechaNegocio::parsear($datos['fecha_entrega_real']))
                : now();

            if ($error = $prestamo->errorFechaEntregaReal($fechaEntrega)) {
                throw ValidationException::withMessages([
                    'fecha_entrega_real' => $error,
                ]);
            }

            // 2. Transición del préstamo (TransicionPrestamoService bloquea el préstamo en segundo lugar)
            // Se debe usar la instancia fresca retornada por el servicio
            $prestamoActualizado = $this->transicionService->transicionar(
                $prestamo,
                EstadoPrestamo::Entregado,
                [
                    'actor' => $personal,
                    'fecha' => $fechaEntrega,
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

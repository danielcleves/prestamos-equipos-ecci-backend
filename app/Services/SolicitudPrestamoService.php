<?php

namespace App\Services;

use App\Enums\EstadoPrestamo;
use App\Models\Equipo;
use App\Models\Prestamo;
use App\Models\User;
use App\Support\FechaNegocio;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SolicitudPrestamoService
{
    public function __construct(
        protected Parametros $parametros,
        protected ElegibilidadService $elegibilidad
    ) {}

    /**
     * Registra una nueva solicitud de préstamo dentro de una transacción.
     *
     * @param  array{
     *     equipo_id: int,
     *     motivo: string,
     *     fecha_inicio: CarbonInterface|string,
     *     fecha_devolucion_estimada: CarbonInterface|string
     * }  $data
     *
     * @throws ValidationException
     */
    public function solicitar(User $usuario, array $data): Prestamo
    {
        return DB::transaction(function () use ($usuario, $data) {
            // Orden de bloqueo único en todo el módulo para prevenir interbloqueos: usuario -> equipo -> préstamo.
            // 1. Primero se bloquea al usuario solicitante con lockForUpdate() para asegurar que peticiones
            // concurrentes del mismo usuario no superen max_activos_por_usuario.
            // Nota: lockForUpdate() no bloquea en SQLite (esta carrera de concurrencia solo se reproduce en MySQL 8.0).
            $solicitante = User::whereKey($usuario->getKey())->lockForUpdate()->firstOrFail();

            // 2. A continuación se bloquea el equipo con lockForUpdate() antes de validar su disponibilidad y cruce de fechas.
            $equipo = Equipo::whereKey($data['equipo_id'])->lockForUpdate()->firstOrFail();

            // 3. RN-01: el solicitante debe ser elegible para el equipo y la
            // categoría (sanciones, préstamos vencidos, capacitación y
            // restricción por tipo de equipo). Se consulta con los datos ya
            // bloqueados, dentro de la misma transacción.
            $this->elegibilidad->validar($solicitante, $equipo);

            // 4. RN-02: el cupo de préstamos vivos depende del rol del
            // usuario (parámetro cupos.roles), no de un valor fijo.
            $cupo = $this->parametros->cupoActivo($solicitante->rolPrincipal());
            $activosUsuario = Prestamo::where('usuario_id', $solicitante->id)
                // Fuente única de qué cuenta como activo, compartida con el
                // filtro ?activos= del listado (HU-10).
                ->whereIn('estado', EstadoPrestamo::valoresActivos())
                ->count();

            if ($activosUsuario >= $cupo) {
                throw ValidationException::withMessages([
                    'usuario_id' => "Has alcanzado el límite máximo de {$cupo} préstamos activos.",
                ]);
            }

            // 5. El equipo debe cumplir Equipo::isDisponible() (única fuente de verdad de la HU-05)
            if (! $equipo->isDisponible()) {
                if ($equipo->isDadoDeBaja()) {
                    throw ValidationException::withMessages([
                        'equipo_id' => 'El equipo seleccionado ha sido dado de baja y no puede solicitarse.',
                    ]);
                }

                if ($equipo->estado === Equipo::ESTADO_MANTENIMIENTO) {
                    throw ValidationException::withMessages([
                        'equipo_id' => 'El equipo seleccionado se encuentra en mantenimiento y no está disponible para préstamo.',
                    ]);
                }

                throw ValidationException::withMessages([
                    'equipo_id' => 'El equipo seleccionado no se encuentra disponible para préstamo.',
                ]);
            }

            // BUG-0011: FechaNegocio::parsear() ya devuelve UTC, pero un
            // CarbonInterface puede venir en la zona de negocio. Se normaliza
            // sobre una copia para no mutar el objeto del llamador y para que
            // el instante guardado no dependa de la zona con la que se construyó.
            $fechaInicio = $data['fecha_inicio'] instanceof CarbonInterface
                ? $data['fecha_inicio']->copy()->setTimezone('UTC')
                : FechaNegocio::parsear($data['fecha_inicio']);

            $fechaDevolucionEstimada = $data['fecha_devolucion_estimada'] instanceof CarbonInterface
                ? $data['fecha_devolucion_estimada']->copy()->setTimezone('UTC')
                : FechaNegocio::parsear($data['fecha_devolucion_estimada']);

            // 6. No debe existir un préstamo solicitado, aprobado o entregado para ese equipo con fechas que se crucen
            $hayCruce = Prestamo::where('equipo_id', $equipo->id)
                // Solo los préstamos activos bloquean el calendario del equipo.
                ->whereIn('estado', EstadoPrestamo::valoresActivos())
                ->where('fecha_inicio', '<', $fechaDevolucionEstimada)
                ->where('fecha_devolucion_estimada', '>', $fechaInicio)
                ->exists();

            if ($hayCruce) {
                throw ValidationException::withMessages([
                    'equipo_id' => 'El equipo ya cuenta con un préstamo solicitado o activo durante el período seleccionado.',
                ]);
            }

            // 7. Se crea el préstamo en estado solicitado con fecha_solicitud = now().
            // La solicitud NO cambia el estado del equipo.
            // RN-03: las categorías con nivel de restricción >= 1 exigen una
            // autorización adicional antes de poder aprobarse.
            $requiereAutorizacion = $equipo->categoria?->requiereAutorizacion() ?? false;

            return Prestamo::create([
                'usuario_id' => $solicitante->id,
                'equipo_id' => $equipo->id,
                'estado' => EstadoPrestamo::Solicitado,
                'motivo' => trim((string) $data['motivo']),
                'fecha_solicitud' => now(),
                'fecha_inicio' => $fechaInicio,
                'fecha_devolucion_estimada' => $fechaDevolucionEstimada,
                'requiere_autorizacion' => $requiereAutorizacion,
            ]);
        });
    }
}

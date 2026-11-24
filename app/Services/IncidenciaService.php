<?php

namespace App\Services;

use App\Models\Incidencia;
use App\Models\Prestamo;
use App\Models\RevisionEquipo;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * RN-08 — Incidencias por daños.
 *
 * Al devolver un equipo con daños se abre una incidencia ligada a la revisión
 * y al préstamo, que restringe al solicitante durante los días configurados
 * (danos.dias_restriccion). El personal la cierra cuando termina la revisión.
 */
class IncidenciaService
{
    public function __construct(
        protected Parametros $parametros
    ) {}

    /**
     * Abre la incidencia de una devolución con daños.
     *
     * @throws ValidationException
     */
    public function abrir(Prestamo $prestamo, RevisionEquipo $revision): Incidencia
    {
        return DB::transaction(function () use ($prestamo, $revision) {
            if (Incidencia::where('prestamo_id', $prestamo->id)->exists()) {
                throw ValidationException::withMessages([
                    'prestamo_id' => 'El préstamo ya tiene una incidencia registrada.',
                ]);
            }

            return Incidencia::create([
                'prestamo_id' => $prestamo->id,
                'equipo_id' => $prestamo->equipo_id,
                'user_id' => $prestamo->usuario_id,
                'revision_id' => $revision->id,
                'descripcion' => $revision->observaciones
                    ?? 'El equipo se devolvió con daños o requiere mantenimiento.',
                'estado' => Incidencia::ESTADO_ABIERTA,
                'restringe_hasta' => now()->addDays($this->parametros->diasRestriccionDanos()),
            ]);
        });
    }

    /**
     * Cierra una incidencia. Solo el personal administrativo llega aquí
     * (middleware de la ruta), pero se valida igual el estado.
     *
     * @throws ValidationException
     */
    public function cerrar(Incidencia $incidencia, User $personal): Incidencia
    {
        return DB::transaction(function () use ($incidencia, $personal) {
            $fresca = Incidencia::whereKey($incidencia->getKey())->lockForUpdate()->firstOrFail();

            if ($fresca->estaCerrada()) {
                throw ValidationException::withMessages([
                    'estado' => 'La incidencia ya está cerrada.',
                ]);
            }

            $fresca->update([
                'estado' => Incidencia::ESTADO_CERRADA,
                'resuelta_por' => $personal->id,
                'fecha_cierre' => now(),
            ]);

            return $fresca;
        });
    }
}

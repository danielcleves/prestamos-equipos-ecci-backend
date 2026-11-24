<?php

namespace App\Services;

use App\Models\Prestamo;
use App\Models\Sancion;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * RN-04 — Sanción por mora.
 *
 * Una devolución después de la fecha estimada genera una sanción cuya
 * duración sale de la escala parametrizable (mora.escala). La sanción nace
 * activa y vence a los N días configurados.
 */
class SancionService
{
    public function __construct(
        protected Parametros $parametros
    ) {}

    /**
     * Registra la sanción si la devolución fue tardía. Devuelve la sanción
     * creada o null si el préstamo se devolvió a tiempo.
     */
    public function registrarPorMora(Prestamo $prestamo, CarbonInterface $fechaDevolucion): ?Sancion
    {
        $estimada = $prestamo->fecha_devolucion_estimada;

        if ($estimada === null || ! $fechaDevolucion->greaterThan($estimada)) {
            return null;
        }

        $diasRetraso = $this->diasDeRetraso($estimada, $fechaDevolucion);
        $diasSancion = $this->parametros->diasSancionPorRetraso($diasRetraso);

        if ($diasSancion === null || $diasSancion <= 0) {
            return null;
        }

        return DB::transaction(function () use ($prestamo, $diasRetraso, $diasSancion) {
            $inicio = now();

            return Sancion::create([
                'user_id' => $prestamo->usuario_id,
                'prestamo_id' => $prestamo->id,
                'motivo' => Sancion::MOTIVO_MORA,
                'dias_retraso' => $diasRetraso,
                'dias_sancion' => $diasSancion,
                'fecha_inicio' => $inicio,
                'fecha_fin' => $inicio->copy()->addDays($diasSancion),
                'estado' => Sancion::ESTADO_ACTIVA,
            ]);
        });
    }

    /**
     * Días de calendario de retraso. Si la devolución es el mismo día pero
     * más tarde que la hora estimada, cuenta como un día.
     */
    private function diasDeRetraso(CarbonInterface $estimada, CarbonInterface $devolucion): int
    {
        $dias = (int) abs($estimada->copy()->startOfDay()->diffInDays($devolucion->copy()->startOfDay()));

        return max(1, $dias);
    }
}

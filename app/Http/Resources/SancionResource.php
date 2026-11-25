<?php

namespace App\Http\Resources;

use App\Support\FechaNegocio;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * RN-04: sanción por mora.
 *
 * 'vigente' y 'dias_restantes' se calculan al leer, sin tocar el estado
 * guardado: una sanción 'activa' cuya fecha_fin ya pasó se sigue guardando
 * como 'activa' hasta que la elegibilidad la marca 'cumplida'
 * (ElegibilidadService), pero aquí cuenta como no vigente.
 */
class SancionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'prestamo_id' => $this->prestamo_id,
            'motivo' => $this->motivo,
            'dias_retraso' => $this->dias_retraso,
            'dias_sancion' => $this->dias_sancion,
            'dias_restantes' => $this->diasRestantes(),
            'fecha_inicio' => FechaNegocio::formatear($this->fecha_inicio),
            'fecha_fin' => FechaNegocio::formatear($this->fecha_fin),
            'estado' => $this->estado,
            'vigente' => $this->estaVigente(),
            'created_at' => FechaNegocio::formatear($this->created_at),
        ];
    }
}

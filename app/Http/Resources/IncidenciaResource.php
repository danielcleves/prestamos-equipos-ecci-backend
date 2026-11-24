<?php

namespace App\Http\Resources;

use App\Support\FechaNegocio;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IncidenciaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'prestamo_id' => $this->prestamo_id,
            'equipo_id' => $this->equipo_id,
            'equipo' => $this->whenLoaded('equipo', fn () => [
                'id' => $this->equipo->id,
                'codigo' => $this->equipo->codigo,
                'nombre' => $this->equipo->nombre,
            ]),
            'user_id' => $this->user_id,
            'solicitante' => $this->whenLoaded('solicitante', fn () => [
                'id' => $this->solicitante->id,
                'name' => $this->solicitante->name,
                'email' => $this->solicitante->email,
            ]),
            'revision_id' => $this->revision_id,
            'descripcion' => $this->descripcion,
            'estado' => $this->estado,
            'restringe_hasta' => FechaNegocio::formatear($this->restringe_hasta),
            'resuelta_por' => $this->resuelta_por,
            'fecha_cierre' => FechaNegocio::formatear($this->fecha_cierre),
            'created_at' => FechaNegocio::formatear($this->created_at),
            'updated_at' => FechaNegocio::formatear($this->updated_at),
        ];
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EquipoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $disponible = $this->resource->isDisponible();

        return [
            'id' => $this->id,
            'codigo' => $this->codigo,
            'nombre' => $this->nombre,
            'categoria' => $this->whenLoaded('categoria', fn () => [
                'id' => $this->categoria->id,
                'nombre' => $this->categoria->nombre,
            ]),
            'descripcion' => $this->descripcion,
            'disponible' => $disponible,
            'puede_solicitarse' => $disponible,
            'estado_disponibilidad' => $disponible ? 'disponible' : 'no_disponible',
            'estado' => $this->estado,
            // Pendiente confirmar con PO si el rol usuario debe verla
            'observaciones' => $this->observaciones,
        ];
    }
}

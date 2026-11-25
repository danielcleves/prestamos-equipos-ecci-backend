<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoriaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'nivel_restriccion' => (int) $this->nivel_restriccion,
            // RN-01: exige capacitación en esta categoría para solicitar.
            'requiere_capacitacion' => (bool) $this->requiere_capacitacion,
        ];
    }
}

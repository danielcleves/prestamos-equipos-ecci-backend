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
                // RN-01 / RN-03: 0 libre, 1 requiere autorización, 2 solo
                // personal administrativo.
                'nivel_restriccion' => (int) $this->categoria->nivel_restriccion,
            ]),
            // RN-06: solo se expone en el detalle del equipo, donde el
            // controlador calcula el límite según categoría y rol. En los
            // listados (o anidado en un préstamo) se omite para no multiplicar
            // consultas de parámetros por fila.
            'duracion_maxima_dias' => $this->whenNotNull($this->duracion_maxima_dias),
            'descripcion' => $this->descripcion,
            'disponible' => $disponible,
            'puede_solicitarse' => $disponible,
            'estado_disponibilidad' => $disponible ? 'disponible' : 'no_disponible',
            'estado' => $this->estado,
            'observaciones' => $this->when(
                $request->user()?->esPersonalAdministrativo() ?? false,
                $this->observaciones
            ),
        ];
    }
}

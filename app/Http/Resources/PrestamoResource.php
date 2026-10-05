<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrestamoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'usuario_id' => $this->usuario_id,
            'solicitante' => $this->whenLoaded('solicitante', fn () => new UserResource($this->solicitante)),
            'equipo_id' => $this->equipo_id,
            'equipo' => $this->whenLoaded('equipo', fn () => new EquipoResource($this->equipo)),
            'estado' => $this->estado->value,
            'estado_etiqueta' => $this->estado->etiqueta(),
            'motivo' => $this->motivo,
            'fecha_solicitud' => $this->fecha_solicitud?->toIso8601String(),
            'fecha_inicio' => $this->fecha_inicio?->toIso8601String(),
            'fecha_devolucion_estimada' => $this->fecha_devolucion_estimada?->toIso8601String(),
            'fecha_aprobacion' => $this->fecha_aprobacion?->toIso8601String(),
            'fecha_entrega_real' => $this->fecha_entrega_real?->toIso8601String(),
            'fecha_devolucion_real' => $this->fecha_devolucion_real?->toIso8601String(),
            'condicion_entrega' => $this->condicion_entrega?->value,
            'condicion_entrega_etiqueta' => $this->condicion_entrega?->etiqueta(),
            'condicion_devolucion' => $this->condicion_devolucion?->value,
            'condicion_devolucion_etiqueta' => $this->condicion_devolucion?->etiqueta(),
            'entregado_por' => $this->entregado_por,
            'usuario_entrega' => $this->whenLoaded('entregadoPor', fn () => new UserResource($this->entregadoPor)),
            'recibido_por' => $this->recibido_por,
            'usuario_recepcion' => $this->whenLoaded('recibidoPor', fn () => new UserResource($this->recibidoPor)),
            'observaciones' => $this->observaciones,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

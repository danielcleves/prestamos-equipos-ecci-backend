<?php

namespace App\Http\Resources;

use App\Support\FechaNegocio;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrestamoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $esPersonal = $request->user()?->esPersonalAdministrativo() ?? false;

        return [
            'id' => $this->id,
            'usuario_id' => $this->usuario_id,
            'solicitante' => $this->whenLoaded('solicitante', function () use ($esPersonal) {
                return [
                    'id' => $this->solicitante->id,
                    'name' => $this->solicitante->name,
                    'email' => $this->when($esPersonal, $this->solicitante->email),
                    'is_active' => $this->when($esPersonal, $this->solicitante->is_active),
                    'roles' => $this->when($esPersonal, fn () => $this->solicitante->getRoleNames()),
                ];
            }),
            'equipo_id' => $this->equipo_id,
            'equipo' => $this->whenLoaded('equipo', fn () => new EquipoResource($this->equipo)),
            'estado' => $this->estado->value,
            'estado_etiqueta' => $this->estado->etiqueta(),
            'motivo' => $this->motivo,
            'fecha_solicitud' => FechaNegocio::formatear($this->fecha_solicitud),
            'fecha_inicio' => FechaNegocio::formatear($this->fecha_inicio),
            'fecha_devolucion_estimada' => FechaNegocio::formatear($this->fecha_devolucion_estimada),
            'fecha_aprobacion' => FechaNegocio::formatear($this->fecha_aprobacion),
            'fecha_entrega_real' => FechaNegocio::formatear($this->fecha_entrega_real),
            'fecha_devolucion_real' => FechaNegocio::formatear($this->fecha_devolucion_real),
            'condicion_entrega' => $this->condicion_entrega?->value,
            'condicion_entrega_etiqueta' => $this->condicion_entrega?->etiqueta(),
            'condicion_devolucion' => $this->condicion_devolucion?->value,
            'condicion_devolucion_etiqueta' => $this->condicion_devolucion?->etiqueta(),
            'entregado_por' => $this->entregado_por,
            'usuario_entrega' => $this->whenLoaded('entregadoPor', fn () => [
                'id' => $this->entregadoPor->id,
                'name' => $this->entregadoPor->name,
            ]),
            'recibido_por' => $this->recibido_por,
            'usuario_recepcion' => $this->whenLoaded('recibidoPor', fn () => [
                'id' => $this->recibidoPor->id,
                'name' => $this->recibidoPor->name,
            ]),
            'observaciones_entrega' => $this->when($esPersonal, $this->observaciones_entrega),
            'observaciones_devolucion' => $this->when($esPersonal, $this->observaciones_devolucion),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

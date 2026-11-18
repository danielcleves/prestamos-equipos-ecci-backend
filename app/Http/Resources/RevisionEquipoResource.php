<?php

namespace App\Http\Resources;

use App\Support\FechaNegocio;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * HU-12: revision del estado del equipo al momento de la devolucion.
 *
 * Las observaciones son el unico campo restringido: siguen el mismo criterio
 * que observaciones_entrega / observaciones_devolucion en PrestamoResource y
 * se exponen solo al personal administrativo. El solicitante si ve la
 * condicion y su etiqueta.
 */
class RevisionEquipoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $esPersonal = $request->user()?->esPersonalAdministrativo() ?? false;

        return [
            'id' => $this->id,
            'prestamo_id' => $this->prestamo_id,
            'equipo_id' => $this->equipo_id,
            'condicion' => $this->condicion->value,
            'condicion_etiqueta' => $this->condicion->etiqueta(),
            'tiene_danos' => $this->tiene_danos,
            'observaciones' => $this->when($esPersonal, $this->observaciones),
            // Solo el id: cargar la relación del revisor añadiría una consulta
            // al presupuesto del listado de préstamos, y su nombre ya viaja en
            // 'usuario_recepcion' del préstamo (es el mismo actor).
            'revisado_por' => $this->revisado_por,
            'fecha_revision' => FechaNegocio::formatear($this->fecha_revision),
            // Reservado para la HU-31; nulo mientras no existan incidencias.
            'incidencia_id' => $this->incidencia_id,
        ];
    }
}

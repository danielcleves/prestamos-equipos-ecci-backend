<?php

namespace App\Http\Resources;

use App\Models\Rol;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Rol con su perfil de préstamo (RN-02).
 *
 * @mixin Rol
 */
class RolResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->name,
            'etiqueta' => $this->etiquetaVisible(),
            'descripcion' => $this->descripcion,
            'cupo_prestamos' => (int) $this->cupo_prestamos,
            'es_sistema' => $this->esSistema(),
            'es_predeterminado' => (bool) $this->es_predeterminado,
            'usuarios_count' => $this->whenCounted('users'),
        ];
    }
}

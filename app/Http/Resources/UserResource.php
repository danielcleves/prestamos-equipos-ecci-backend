<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // RN-04: se resuelve una sola vez por usuario; el modelo filtra en
        // memoria si la relación viene cargada (listados) y consulta si no.
        $sancionActiva = $this->sancionVigente();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_active' => $this->is_active,
            'capacitado' => (bool) $this->capacitado,
            'roles' => $this->getRoleNames(),
            // RN-02: rol que decide el cupo y su nombre para mostrar.
            'rol_principal' => $this->rolPrincipal(),
            'rol_etiqueta' => $this->roles->firstWhere('name', $this->rolPrincipal())?->etiquetaVisible(),
            // RN-04: sanción por mora vigente, o null si puede solicitar.
            'sancion_activa' => $sancionActiva !== null ? new SancionResource($sancionActiva) : null,
        ];
    }
}

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
        ];
    }
}

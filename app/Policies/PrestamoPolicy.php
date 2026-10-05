<?php

namespace App\Policies;

use App\Models\Prestamo;
use App\Models\User;

class PrestamoPolicy
{
    /**
     * Determina si el usuario puede listar préstamos.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determina si el usuario puede ver el detalle de un préstamo específico.
     * Admin y encargado pueden ver cualquiera; usuario común solo sus propios préstamos.
     */
    public function view(User $user, Prestamo $prestamo): bool
    {
        if ($user->esPersonalAdministrativo()) {
            return true;
        }

        return $prestamo->usuario_id === $user->id;
    }
}

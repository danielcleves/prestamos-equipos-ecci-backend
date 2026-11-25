<?php

namespace App\Observers;

use App\Models\Equipo;
use App\Models\HistorialEstado;
use Illuminate\Support\Facades\Auth;

/**
 * Centraliza el registro de historial_estados (HU-04: "el cambio debe
 * quedar registrado") en un solo lugar, para que no dependa de que cada
 * controlador que toque 'estado' se acuerde de loguearlo a mano.
 */
class EquipoObserver
{
    public function created(Equipo $equipo): void
    {
        HistorialEstado::create([
            'equipo_id' => $equipo->id,
            'estado_anterior' => null,
            'estado_nuevo' => $equipo->estado,
            'user_id' => Auth::id(),
        ]);
    }

    public function updated(Equipo $equipo): void
    {
        if (! $equipo->wasChanged('estado')) {
            return;
        }

        HistorialEstado::create([
            'equipo_id' => $equipo->id,
            'estado_anterior' => $equipo->getOriginal('estado'),
            'estado_nuevo' => $equipo->estado,
            'user_id' => Auth::id(),
        ]);
    }
}

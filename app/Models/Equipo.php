<?php

namespace App\Models;

use Database\Factories\EquipoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['codigo', 'nombre', 'categoria_id', 'descripcion', 'estado', 'observaciones'])]
class Equipo extends Model
{
    /** @use HasFactory<EquipoFactory> */
    use HasFactory;

    public const ESTADOS = ['disponible', 'en_prestamo', 'mantenimiento', 'dado_de_baja'];

    public const ESTADO_INICIAL = 'disponible';

    /**
     * Estado terminal (HU-04): un equipo dado de baja no puede volver a
     * cambiar de estado, para que nunca pueda llegar a 'en_prestamo'.
     */
    public const ESTADO_TERMINAL = 'dado_de_baja';

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }

    public function historialEstados(): HasMany
    {
        // latest('id') en vez de latest(): varios cambios de estado pueden
        // caer en el mismo segundo y created_at no alcanza a distinguirlos;
        // el id si es monotono.
        return $this->hasMany(HistorialEstado::class)->latest('id');
    }
}

<?php

namespace App\Models;

use Database\Factories\EquipoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['codigo', 'nombre', 'categoria_id', 'descripcion', 'estado', 'observaciones'])]
class Equipo extends Model
{
    /** @use HasFactory<EquipoFactory> */
    use HasFactory;

    /**
     * Estados validos (HU-04 se encarga de las transiciones entre ellos;
     * aca solo se usan para el estado inicial y su validacion).
     */
    public const ESTADOS = ['disponible', 'en_prestamo', 'mantenimiento'];

    public const ESTADO_INICIAL = 'disponible';

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }
}

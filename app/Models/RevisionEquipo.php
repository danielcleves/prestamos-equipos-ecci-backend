<?php

namespace App\Models;

use App\Enums\CondicionEquipo;
use Database\Factories\RevisionEquipoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HU-12: revision del estado del equipo al momento de la devolucion.
 *
 * Es la fuente de verdad de la revision fisica. Las columnas
 * condicion_devolucion / observaciones_devolucion / recibido_por de
 * 'prestamos' se mantienen como espejo de solo lectura, escrito en la misma
 * transaccion (ver docs/decisiones.md).
 */
#[Fillable([
    'prestamo_id',
    'equipo_id',
    'condicion',
    'tiene_danos',
    'observaciones',
    'revisado_por',
    'fecha_revision',
    'incidencia_id',
])]
class RevisionEquipo extends Model
{
    /** @use HasFactory<RevisionEquipoFactory> */
    use HasFactory;

    /**
     * El nombre por convencion de Eloquent seria 'revision_equipos'; la tabla
     * acordada es 'revisiones_equipo' (plural en español sobre el sustantivo
     * principal, igual que 'historial_estados').
     *
     * @var string
     */
    protected $table = 'revisiones_equipo';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'condicion' => CondicionEquipo::class,
            'tiene_danos' => 'boolean',
            'fecha_revision' => 'datetime',
        ];
    }

    /**
     * Determina si una condicion reportada constituye una novedad.
     * Unica fuente de verdad para derivar 'tiene_danos', de modo que agregar
     * un valor al enum no obligue a revisar cada punto de escritura.
     */
    public static function condicionTieneDanos(CondicionEquipo $condicion): bool
    {
        return $condicion !== CondicionEquipo::Bueno;
    }

    /**
     * Prestamo al que pertenece la revision.
     */
    public function prestamo(): BelongsTo
    {
        return $this->belongsTo(Prestamo::class, 'prestamo_id');
    }

    /**
     * Equipo revisado.
     */
    public function equipo(): BelongsTo
    {
        return $this->belongsTo(Equipo::class, 'equipo_id');
    }

    /**
     * Miembro del personal administrativo que realizo la revision.
     */
    public function revisadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisado_por');
    }

    /**
     * Scope para filtrar unicamente revisiones con novedad (daños o
     * necesidad de mantenimiento). Pensado para alimentar la HU-31.
     *
     * @param  Builder<RevisionEquipo>  $query
     * @return Builder<RevisionEquipo>
     */
    public function scopeConDanos(Builder $query): Builder
    {
        return $query->where('tiene_danos', true);
    }
}

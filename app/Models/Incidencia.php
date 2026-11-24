<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RN-08: incidencia abierta al devolver un equipo con daños o que requiere
 * mantenimiento. Restringe temporalmente al solicitante.
 */
#[Fillable([
    'prestamo_id',
    'equipo_id',
    'user_id',
    'revision_id',
    'descripcion',
    'estado',
    'restringe_hasta',
    'resuelta_por',
    'fecha_cierre',
])]
class Incidencia extends Model
{
    /** El pluralizador de Eloquent no conoce el español: tabla explícita. */
    protected $table = 'incidencias';

    public const ESTADO_ABIERTA = 'abierta';

    public const ESTADO_EN_REVISION = 'en_revision';

    public const ESTADO_CERRADA = 'cerrada';

    public const ESTADOS = [
        self::ESTADO_ABIERTA,
        self::ESTADO_EN_REVISION,
        self::ESTADO_CERRADA,
    ];

    /**
     * Estados que todavía restringen al usuario.
     *
     * @var array<int, string>
     */
    public const ESTADOS_ABIERTOS = [
        self::ESTADO_ABIERTA,
        self::ESTADO_EN_REVISION,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'restringe_hasta' => 'datetime',
            'fecha_cierre' => 'datetime',
        ];
    }

    public function prestamo(): BelongsTo
    {
        return $this->belongsTo(Prestamo::class, 'prestamo_id');
    }

    public function equipo(): BelongsTo
    {
        return $this->belongsTo(Equipo::class, 'equipo_id');
    }

    /**
     * Solicitante del préstamo, que es el usuario restringido.
     */
    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(RevisionEquipo::class, 'revision_id');
    }

    public function resueltaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resuelta_por');
    }

    /**
     * Incidencias que todavía restringen: abiertas (o en revisión) y con la
     * restricción vigente.
     *
     * @param  Builder<Incidencia>  $query
     * @return Builder<Incidencia>
     */
    public function scopeRestringentes(Builder $query): Builder
    {
        return $query->whereIn('estado', self::ESTADOS_ABIERTOS)
            ->where('restringe_hasta', '>=', now());
    }

    public function estaCerrada(): bool
    {
        return $this->estado === self::ESTADO_CERRADA;
    }
}

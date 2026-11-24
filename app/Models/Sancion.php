<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RN-04: sanción por mora.
 *
 * Nace 'activa' con fecha_fin = inicio + días de sanción. Una sanción vencida
 * se considera 'cumplida' al consultarla (ver scopeVigentes y
 * ElegibilidadService).
 */
#[Fillable([
    'user_id',
    'prestamo_id',
    'motivo',
    'dias_retraso',
    'dias_sancion',
    'fecha_inicio',
    'fecha_fin',
    'estado',
])]
class Sancion extends Model
{
    /** El pluralizador de Eloquent no conoce el español: tabla explícita. */
    protected $table = 'sanciones';

    public const ESTADO_ACTIVA = 'activa';

    public const ESTADO_CUMPLIDA = 'cumplida';

    public const ESTADO_ANULADA = 'anulada';

    public const MOTIVO_MORA = 'mora';

    public const ESTADOS = [
        self::ESTADO_ACTIVA,
        self::ESTADO_CUMPLIDA,
        self::ESTADO_ANULADA,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dias_retraso' => 'integer',
            'dias_sancion' => 'integer',
            'fecha_inicio' => 'datetime',
            'fecha_fin' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function prestamo(): BelongsTo
    {
        return $this->belongsTo(Prestamo::class, 'prestamo_id');
    }

    /**
     * Sanciones activas y todavía vigentes.
     *
     * @param  Builder<Sancion>  $query
     * @return Builder<Sancion>
     */
    public function scopeVigentes(Builder $query): Builder
    {
        return $query->where('estado', self::ESTADO_ACTIVA)
            ->where('fecha_fin', '>=', now());
    }

    /**
     * Una sanción activa cuya fecha_fin ya pasó cuenta como cumplida.
     */
    public function estaVigente(): bool
    {
        return $this->estado === self::ESTADO_ACTIVA && $this->fecha_fin !== null && $this->fecha_fin->greaterThanOrEqualTo(now());
    }
}

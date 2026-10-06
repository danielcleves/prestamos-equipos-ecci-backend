<?php

namespace App\Models;

use App\Enums\CondicionEquipo;
use App\Enums\EstadoPrestamo;
use Carbon\CarbonInterface;
use Database\Factories\PrestamoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'usuario_id',
    'equipo_id',
    'estado',
    'motivo',
    'fecha_solicitud',
    'fecha_inicio',
    'fecha_devolucion_estimada',
    'fecha_aprobacion',
    'fecha_entrega_real',
    'fecha_devolucion_real',
    'condicion_entrega',
    'condicion_devolucion',
    'entregado_por',
    'recibido_por',
    'observaciones_entrega',
    'observaciones_devolucion',
])]
class Prestamo extends Model
{
    /** @use HasFactory<PrestamoFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estado' => EstadoPrestamo::class,
            'condicion_entrega' => CondicionEquipo::class,
            'condicion_devolucion' => CondicionEquipo::class,
            'fecha_solicitud' => 'datetime',
            'fecha_inicio' => 'datetime',
            'fecha_devolucion_estimada' => 'datetime',
            'fecha_aprobacion' => 'datetime',
            'fecha_entrega_real' => 'datetime',
            'fecha_devolucion_real' => 'datetime',
        ];
    }

    /**
     * Usuario solicitante del préstamo.
     */
    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /**
     * Equipo objeto del préstamo.
     */
    public function equipo(): BelongsTo
    {
        return $this->belongsTo(Equipo::class, 'equipo_id');
    }

    /**
     * Miembro del personal que registró la entrega del equipo.
     */
    public function entregadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entregado_por');
    }

    /**
     * Miembro del personal que registró la recepción en la devolución.
     */
    public function recibidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recibido_por');
    }

    /**
     * Valida la coherencia temporal de la fecha de entrega real.
     * Retorna el mensaje de error en español o null si es válida.
     */
    public function errorFechaEntregaReal(CarbonInterface $fecha): ?string
    {
        if ($fecha->isAfter(now()->addMinute())) {
            return 'La fecha de entrega real no puede ser posterior al momento actual.';
        }

        if ($this->fecha_aprobacion && $fecha->isBefore($this->fecha_aprobacion)) {
            return 'La fecha de entrega real no puede ser anterior a la fecha de aprobación del préstamo.';
        }

        return null;
    }

    /**
     * Valida la coherencia temporal de la fecha de devolución real.
     * Retorna el mensaje de error en español o null si es válida.
     */
    public function errorFechaDevolucionReal(CarbonInterface $fecha): ?string
    {
        if ($fecha->isAfter(now()->addMinute())) {
            return 'La fecha de devolución real no puede ser posterior al momento actual.';
        }

        if ($this->fecha_entrega_real && $fecha->isBefore($this->fecha_entrega_real)) {
            return 'La fecha de devolución real no puede ser anterior a la fecha de entrega real.';
        }

        return null;
    }
}

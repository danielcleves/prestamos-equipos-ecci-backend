<?php

namespace App\Models;

use App\Enums\CondicionEquipo;
use App\Enums\EstadoPrestamo;
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
    'observaciones',
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
     * Agrega una observación con el prefijo del momento sin sobrescribir la existente.
     * Encapsulado para que un cambio a múltiples columnas quede localizado en este punto.
     */
    public function agregarObservacion(string $momento, ?string $texto): void
    {
        $textoLimpio = trim($texto ?? '');
        if ($textoLimpio === '') {
            return;
        }

        $etiqueta = ucfirst(trim($momento));
        $nuevaLinea = "{$etiqueta}: {$textoLimpio}";

        $this->observaciones = ($this->observaciones !== null && trim($this->observaciones) !== '')
            ? $this->observaciones."\n".$nuevaLinea
            : $nuevaLinea;
    }
}

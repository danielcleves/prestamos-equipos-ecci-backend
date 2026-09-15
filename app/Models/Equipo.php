<?php

namespace App\Models;

use App\Observers\EquipoObserver;
use Database\Factories\EquipoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['codigo', 'nombre', 'categoria_id', 'descripcion', 'estado', 'observaciones'])]
#[ObservedBy([EquipoObserver::class])]
class Equipo extends Model
{
    /** @use HasFactory<EquipoFactory> */
    use HasFactory;

    public const ESTADO_DISPONIBLE = 'disponible';

    public const ESTADO_INICIAL = self::ESTADO_DISPONIBLE;

    public const ESTADO_DADO_DE_BAJA = 'dado_de_baja';

    /**
     * Estado terminal (HU-04): un equipo dado de baja no puede volver a
     * cambiar de estado, para que nunca pueda llegar a 'en_prestamo'.
     */
    public const ESTADO_TERMINAL = self::ESTADO_DADO_DE_BAJA;

    public const ESTADOS = [self::ESTADO_DISPONIBLE, 'en_prestamo', 'mantenimiento', self::ESTADO_DADO_DE_BAJA];

    /**
     * Determina si el usuario tiene permisos para ver equipos dados de baja.
     * Seguro por defecto: solo admin y encargado pueden verlos; cualquier otro
     * rol o usuario sin roles no tiene acceso a ellos.
     */
    public static function puedeVerDadosDeBaja(?User $user): bool
    {
        return $user?->hasAnyRole(['admin', 'encargado']) ?? false;
    }

    /**
     * Determina si el equipo esta disponible para prestamo.
     *
     * Esta regla y el scopeDisponible() son la unica fuente de verdad de
     * disponibilidad en el sistema; la HU-06 debe consumirlos para validar
     * y rechazar solicitudes sobre equipos no disponibles.
     */
    public function isDisponible(): bool
    {
        return $this->estado === self::ESTADO_DISPONIBLE;
    }

    /**
     * Determina si el equipo ha sido dado de baja.
     */
    public function isDadoDeBaja(): bool
    {
        return $this->estado === self::ESTADO_DADO_DE_BAJA;
    }

    /**
     * Scope para filtrar unicamente equipos disponibles.
     * Reutilizable por la HU-06 para validar solicitudes de prestamo.
     */
    public function scopeDisponible(Builder $query): Builder
    {
        return $query->where('estado', self::ESTADO_DISPONIBLE);
    }

    /**
     * Scope para filtrar equipos no disponibles.
     */
    public function scopeNoDisponible(Builder $query): Builder
    {
        return $query->where('estado', '!=', self::ESTADO_DISPONIBLE);
    }

    /**
     * Scope para equipos visibles en el catalogo general.
     * Excluye equipos dados de baja, mientras que admin y encargado si pueden verlos.
     */
    public function scopeVisiblesEnCatalogo(Builder $query): Builder
    {
        return $query->where('estado', '!=', self::ESTADO_DADO_DE_BAJA);
    }

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

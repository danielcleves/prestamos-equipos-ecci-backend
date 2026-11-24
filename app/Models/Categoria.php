<?php

namespace App\Models;

use Database\Factories\CategoriaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['nombre', 'nivel_restriccion'])]
class Categoria extends Model
{
    /** @use HasFactory<CategoriaFactory> */
    use HasFactory;

    /**
     * Niveles de restricción del tipo de equipo (RN-01, RN-03).
     */
    public const LIBRE = 0;

    public const REQUIERE_AUTORIZACION = 1;

    public const SOLO_PERSONAL = 2;

    public const NIVELES = [
        self::LIBRE,
        self::REQUIERE_AUTORIZACION,
        self::SOLO_PERSONAL,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nivel_restriccion' => 'integer',
        ];
    }

    public function equipos(): HasMany
    {
        return $this->hasMany(Equipo::class);
    }

    /**
     * Indica si el equipo de esta categoría requiere autorización adicional
     * antes de aprobarse (RN-03).
     */
    public function requiereAutorizacion(): bool
    {
        return $this->nivel_restriccion >= self::REQUIERE_AUTORIZACION;
    }

    /**
     * Indica si solo el personal administrativo puede solicitar equipos de
     * esta categoría (RN-01).
     */
    public function esSoloPersonal(): bool
    {
        return $this->nivel_restriccion >= self::SOLO_PERSONAL;
    }
}

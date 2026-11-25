<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Rol del sistema con su perfil de préstamo (RN-02).
 *
 * - «admin» y «encargado» son roles de sistema: dan permisos de personal y no
 *   se pueden eliminar ni marcar como predeterminados.
 * - El resto son roles de solicitante que crea el admin (estudiante,
 *   profesor, coordinador…). No dan permisos extra; solo cambian el cupo de
 *   préstamos vivos y los días extra de duración (RN-06).
 * - El rol predeterminado (por defecto «estudiante») es el que recibe una
 *   cuenta creada por auto-registro.
 */
class Rol extends Role
{
    /** Roles con permisos de personal; su nombre está fijado en las rutas. */
    public const SISTEMA = ['admin', 'encargado'];

    /** Rol predeterminado cuando ninguno está marcado. */
    public const PREDETERMINADO = 'estudiante';

    /** Cupo de respaldo si no existe ningún rol (instalación vacía). */
    public const CUPO_RESPALDO = 3;

    /** Tope de cupo que acepta la API (evita valores absurdos). */
    public const CUPO_MAXIMO = 1000;

    protected $table = 'roles';

    protected function casts(): array
    {
        return [
            'cupo_prestamos' => 'integer',
            'es_predeterminado' => 'boolean',
        ];
    }

    /**
     * Usuarios con este rol. Se fija el modelo User en vez de resolverlo por
     * el guard del rol, que no está disponible en withCount('users').
     */
    public function users(): BelongsToMany
    {
        return $this->morphedByMany(
            User::class,
            'model',
            config('permission.table_names.model_has_roles'),
            app(PermissionRegistrar::class)->pivotRole,
            config('permission.column_names.model_morph_key')
        );
    }

    /**
     * Indica si otra fila ya usa esa etiqueta, sin distinguir mayúsculas ni
     * depender de LOWER() del motor (SQLite solo baja ASCII).
     */
    public static function etiquetaEnUso(string $etiqueta, ?int $excepto = null): bool
    {
        $buscada = mb_strtolower(trim($etiqueta));

        return static::query()
            ->when($excepto !== null, fn ($q) => $q->whereKeyNot($excepto))
            ->pluck('etiqueta')
            ->contains(fn ($e) => is_string($e) && mb_strtolower(trim($e)) === $buscada);
    }

    /** Nombres reservados que un rol de solicitante no puede usar. */
    public const RESERVADOS = ['admin', 'encargado', 'usuario', 'administrador'];

    public function esSistema(): bool
    {
        return in_array($this->name, self::SISTEMA, true);
    }

    /**
     * Texto para mostrar: la etiqueta o, si no hay, el nombre capitalizado.
     */
    public function etiquetaVisible(): string
    {
        return $this->etiqueta ?: ucfirst(str_replace('_', ' ', $this->name));
    }

    /**
     * Rol que reciben las cuentas nuevas de solicitante.
     */
    public static function predeterminado(): ?self
    {
        return static::query()->where('es_predeterminado', true)->orderBy('id')->first()
            ?? static::query()->where('name', self::PREDETERMINADO)->first()
            ?? static::query()->whereNotIn('name', self::SISTEMA)->orderBy('id')->first();
    }

    /**
     * Mapa «nombre => cupo» de todos los roles.
     *
     * @return Collection<string, int>
     */
    public static function mapaCupos(): Collection
    {
        return static::query()->orderBy('id')->pluck('cupo_prestamos', 'name')
            ->map(fn ($cupo) => (int) $cupo);
    }
}

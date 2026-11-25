<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'is_active', 'capacitado'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'capacitado' => 'boolean',
        ];
    }

    /**
     * Determina si el usuario pertenece al personal administrativo (admin o encargado).
     */
    public function esPersonalAdministrativo(): bool
    {
        return $this->hasAnyRole(['admin', 'encargado']);
    }

    /**
     * Rol principal del usuario para los cupos y la duración máxima
     * (RN-02, RN-06): admin > encargado > su rol de solicitante. Sin roles se
     * asume el rol predeterminado (por defecto «estudiante»).
     */
    public function rolPrincipal(): string
    {
        $nombres = $this->getRoleNames();

        foreach (Rol::SISTEMA as $sistema) {
            if ($nombres->contains($sistema)) {
                return $sistema;
            }
        }

        return $nombres->first()
            ?? Rol::predeterminado()?->name
            ?? Rol::PREDETERMINADO;
    }

    /**
     * Sanciones por mora (RN-04).
     */
    public function sanciones(): HasMany
    {
        return $this->hasMany(Sancion::class)->latest('id');
    }

    /**
     * Sanción vigente que bloquea al usuario (RN-04), o null si no tiene.
     *
     * Usa la misma definición que ElegibilidadService: cuenta como activa solo
     * si el estado guardado es 'activa' y fecha_fin no ha pasado. Si la
     * relación 'sanciones' viene cargada se filtra en memoria, para que los
     * listados paginados no disparen una consulta por fila.
     */
    public function sancionVigente(): ?Sancion
    {
        if ($this->relationLoaded('sanciones')) {
            return $this->sanciones->first(fn (Sancion $sancion) => $sancion->estaVigente());
        }

        return $this->sanciones()->vigentes()->first();
    }

    /**
     * Incidencias en las que este usuario es el solicitante restringido
     * (RN-08).
     */
    public function incidencias(): HasMany
    {
        return $this->hasMany(Incidencia::class)->latest('id');
    }
}

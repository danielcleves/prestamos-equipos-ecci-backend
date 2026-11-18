<?php

namespace Database\Factories;

use App\Enums\CondicionEquipo;
use App\Models\Equipo;
use App\Models\Prestamo;
use App\Models\RevisionEquipo;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Spatie\Permission\Models\Role;

/**
 * @extends Factory<RevisionEquipo>
 */
class RevisionEquipoFactory extends Factory
{
    protected $model = RevisionEquipo::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'prestamo_id' => Prestamo::factory()->entregado(),
            'equipo_id' => Equipo::factory(),
            'condicion' => CondicionEquipo::Bueno,
            'tiene_danos' => false,
            'observaciones' => null,
            'revisado_por' => fn () => $this->personal()->id,
            'fecha_revision' => now(),
            'incidencia_id' => null,
        ];
    }

    /**
     * Revision en buen estado, sin novedades.
     */
    public function bueno(): static
    {
        return $this->state(fn (array $attributes) => [
            'condicion' => CondicionEquipo::Bueno,
            'tiene_danos' => false,
        ]);
    }

    /**
     * Revision con daños reportados. Las observaciones son obligatorias en el
     * flujo de la API cuando hay novedad, asi que la factory las provee.
     */
    public function conDanos(?string $observaciones = null): static
    {
        return $this->state(fn (array $attributes) => [
            'condicion' => CondicionEquipo::ConDanos,
            'tiene_danos' => true,
            'observaciones' => $observaciones
                ?? $attributes['observaciones']
                ?? 'Carcasa golpeada y una bisagra suelta.',
        ]);
    }

    /**
     * Revision que deriva en mantenimiento.
     */
    public function requiereMantenimiento(?string $observaciones = null): static
    {
        return $this->state(fn (array $attributes) => [
            'condicion' => CondicionEquipo::RequiereMantenimiento,
            'tiene_danos' => true,
            'observaciones' => $observaciones
                ?? $attributes['observaciones']
                ?? 'La bateria no retiene carga.',
        ]);
    }

    /**
     * Revision ya vinculada a una incidencia (HU-31). La columna no tiene
     * foreign key todavia, por eso se admite un id arbitrario.
     */
    public function conIncidencia(int $incidenciaId = 1): static
    {
        return $this->state(fn (array $attributes) => [
            'incidencia_id' => $incidenciaId,
        ]);
    }

    /**
     * Retorna un usuario con rol de personal (encargado), igual que
     * PrestamoFactory.
     */
    protected function personal(): User
    {
        Role::firstOrCreate(['name' => 'encargado', 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->assignRole('encargado');

        return $user;
    }
}

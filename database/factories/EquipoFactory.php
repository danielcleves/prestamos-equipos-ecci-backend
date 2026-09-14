<?php

namespace Database\Factories;

use App\Models\Categoria;
use App\Models\Equipo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Equipo>
 */
class EquipoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'codigo' => fake()->unique()->bothify('EQ-####'),
            'nombre' => fake()->word(),
            'categoria_id' => Categoria::factory(),
            'descripcion' => fake()->sentence(),
            'estado' => Equipo::ESTADO_INICIAL,
            'observaciones' => null,
        ];
    }
}

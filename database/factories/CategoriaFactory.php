<?php

namespace Database\Factories;

use App\Models\Categoria;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Categoria>
 */
class CategoriaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => fake()->unique()->word(),
        ];
    }

    /**
     * RN-01: la categoría exige capacitación para solicitar sus equipos.
     */
    public function requiereCapacitacion(): static
    {
        return $this->state(fn (array $attributes) => [
            'requiere_capacitacion' => true,
        ]);
    }
}

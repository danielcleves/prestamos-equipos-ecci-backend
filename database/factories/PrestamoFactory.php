<?php

namespace Database\Factories;

use App\Enums\CondicionEquipo;
use App\Enums\EstadoPrestamo;
use App\Models\Equipo;
use App\Models\Prestamo;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Prestamo>
 */
class PrestamoFactory extends Factory
{
    protected $model = Prestamo::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $fechaInicio = now()->addDay()->setHour(9)->setMinute(0)->setSecond(0);
        $fechaDevolucionEstimada = (clone $fechaInicio)->addDays(3)->setHour(17)->setMinute(0)->setSecond(0);

        return [
            'usuario_id' => User::factory(),
            'equipo_id' => Equipo::factory(),
            'estado' => EstadoPrestamo::Solicitado,
            'motivo' => fake()->sentence(8),
            'fecha_solicitud' => now(),
            'fecha_inicio' => $fechaInicio,
            'fecha_devolucion_estimada' => $fechaDevolucionEstimada,
            'fecha_aprobacion' => null,
            'fecha_entrega_real' => null,
            'fecha_devolucion_real' => null,
            'condicion_entrega' => null,
            'condicion_devolucion' => null,
            'entregado_por' => null,
            'recibido_por' => null,
            'observaciones_entrega' => null,
            'observaciones_devolucion' => null,
        ];
    }

    public function solicitado(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => EstadoPrestamo::Solicitado,
        ]);
    }

    public function aprobado(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => EstadoPrestamo::Aprobado,
            'fecha_aprobacion' => now(),
        ]);
    }

    public function entregado(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => EstadoPrestamo::Entregado,
            'fecha_aprobacion' => now()->subDay(),
            'fecha_entrega_real' => now(),
            'condicion_entrega' => CondicionEquipo::Bueno,
            'entregado_por' => User::factory(),
            'observaciones_entrega' => 'En buen estado',
            'observaciones_devolucion' => null,
        ]);
    }

    public function devuelto(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => EstadoPrestamo::Devuelto,
            'fecha_aprobacion' => now()->subDays(3),
            'fecha_entrega_real' => now()->subDays(2),
            'fecha_devolucion_real' => now(),
            'condicion_entrega' => CondicionEquipo::Bueno,
            'condicion_devolucion' => CondicionEquipo::Bueno,
            'entregado_por' => User::factory(),
            'recibido_por' => User::factory(),
            'observaciones_entrega' => 'En buen estado',
            'observaciones_devolucion' => 'Devuelto a tiempo',
        ]);
    }
}

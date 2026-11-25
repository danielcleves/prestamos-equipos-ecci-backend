<?php

namespace Tests\Feature\Api;

use App\Enums\EstadoPrestamo;
use App\Models\Equipo;
use App\Models\Prestamo;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresión de las reglas que ya venían del Sprint 1 y que la iteración 2 no
 * debe romper: disponibilidad por intervalo (RN-05) y estados operativos
 * (RN-07).
 */
class RegresionReglasExistentesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function usuario(): User
    {
        $user = User::factory()->create();
        $user->assignRole('estudiante');

        return $user;
    }

    public function test_rn05_permite_intervalos_contiguos_sin_solapamiento(): void
    {
        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_DISPONIBLE]);
        $inicio = now()->addDays(2)->setTime(9, 0, 0);
        $fin = $inicio->copy()->addDays(2)->setTime(17, 0, 0);

        Prestamo::factory()->create([
            'equipo_id' => $equipo->id,
            'estado' => EstadoPrestamo::Solicitado,
            'fecha_inicio' => $inicio,
            'fecha_devolucion_estimada' => $fin,
        ]);

        // Empieza justo cuando termina el anterior: no hay solapamiento.
        $this->actingAs($this->usuario(), 'sanctum')->postJson('/api/prestamos', [
            'equipo_id' => $equipo->id,
            'motivo' => 'Intervalo contiguo al préstamo existente',
            'fecha_inicio' => $fin->format('Y-m-d H:i:s'),
            'fecha_devolucion_estimada' => $fin->copy()->addDays(1)->format('Y-m-d H:i:s'),
        ])->assertStatus(201);
    }

    public function test_rn07_un_equipo_no_disponible_no_puede_solicitarse(): void
    {
        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_EN_PRESTAMO]);

        $response = $this->actingAs($this->usuario(), 'sanctum')->postJson('/api/prestamos', [
            'equipo_id' => $equipo->id,
            'motivo' => 'Intento sobre un equipo en préstamo',
            'fecha_inicio' => now()->addHour()->format('Y-m-d H:i:s'),
            'fecha_devolucion_estimada' => now()->addDays(2)->format('Y-m-d H:i:s'),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['equipo_id']);
    }
}

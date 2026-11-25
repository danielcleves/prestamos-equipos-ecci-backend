<?php

namespace Tests\Feature\Api;

use App\Enums\CondicionEquipo;
use App\Enums\EstadoPrestamo;
use App\Models\Equipo;
use App\Models\Incidencia;
use App\Models\Prestamo;
use App\Models\Sancion;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * BUG-0010 — revalidar la elegibilidad al ENTREGAR (RN-01).
 *
 * Entre la aprobación y la entrega puede pasar tiempo: si el solicitante
 * recibe una sanción, se le desactiva la cuenta o queda restringido por daños,
 * el equipo no debe salir. Antes solo se validaba al crear la solicitud.
 */
class EntregaRevalidaElegibilidadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function personal(): User
    {
        $user = User::factory()->create();
        $user->assignRole('encargado');

        return $user;
    }

    private function solicitante(): User
    {
        $user = User::factory()->create();
        $user->assignRole('estudiante');

        return $user;
    }

    /**
     * @return array{0: Prestamo, 1: Equipo}
     */
    private function entregaAprobada(User $solicitante): array
    {
        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_DISPONIBLE]);

        $prestamo = Prestamo::factory()->aprobado()->create([
            'usuario_id' => $solicitante->id,
            'equipo_id' => $equipo->id,
        ]);

        return [$prestamo, $equipo];
    }

    private function entregar(Prestamo $prestamo): TestResponse
    {
        return $this->actingAs($this->personal(), 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/entrega", [
                'condicion_entrega' => CondicionEquipo::Bueno->value,
            ]);
    }

    public function test_una_sancion_activa_aparecida_tras_la_aprobacion_bloquea_la_entrega(): void
    {
        $solicitante = $this->solicitante();
        [$prestamo, $equipo] = $this->entregaAprobada($solicitante);

        $prestamoAnterior = Prestamo::factory()->devuelto()->create([
            'usuario_id' => $solicitante->id,
        ]);

        Sancion::create([
            'user_id' => $solicitante->id,
            'prestamo_id' => $prestamoAnterior->id,
            'motivo' => Sancion::MOTIVO_MORA,
            'dias_retraso' => 1,
            'dias_sancion' => 3,
            'fecha_inicio' => now()->subDay(),
            'fecha_fin' => now()->addDays(2),
            'estado' => Sancion::ESTADO_ACTIVA,
        ]);

        $response = $this->entregar($prestamo);

        $response->assertStatus(422)->assertJsonValidationErrors(['usuario_id']);
        $this->assertStringContainsString('sanción activa', $response->json('errors.usuario_id.0'));

        // Nada cambió: el préstamo sigue aprobado y el equipo disponible.
        $this->assertSame(EstadoPrestamo::Aprobado, $prestamo->fresh()->estado);
        $this->assertSame(Equipo::ESTADO_DISPONIBLE, $equipo->fresh()->estado);
    }

    public function test_una_cuenta_desactivada_tras_la_aprobacion_bloquea_la_entrega(): void
    {
        $solicitante = $this->solicitante();
        [$prestamo, $equipo] = $this->entregaAprobada($solicitante);

        User::whereKey($solicitante->id)->update(['is_active' => false]);

        $response = $this->entregar($prestamo);

        $response->assertStatus(422)->assertJsonValidationErrors(['usuario_id']);
        $this->assertStringContainsString('desactivada', $response->json('errors.usuario_id.0'));

        $this->assertSame(EstadoPrestamo::Aprobado, $prestamo->fresh()->estado);
        $this->assertSame(Equipo::ESTADO_DISPONIBLE, $equipo->fresh()->estado);
    }

    public function test_una_incidencia_restringente_aparecida_tras_la_aprobacion_bloquea_la_entrega(): void
    {
        $solicitante = $this->solicitante();
        [$prestamo, $equipo] = $this->entregaAprobada($solicitante);

        $prestamoAnterior = Prestamo::factory()->devuelto()->create([
            'usuario_id' => $solicitante->id,
        ]);

        Incidencia::create([
            'prestamo_id' => $prestamoAnterior->id,
            'equipo_id' => $prestamoAnterior->equipo_id,
            'user_id' => $solicitante->id,
            'descripcion' => 'Equipo devuelto con daños.',
            'estado' => Incidencia::ESTADO_ABIERTA,
            'restringe_hasta' => now()->addDays(7),
        ]);

        $response = $this->entregar($prestamo);

        $response->assertStatus(422)->assertJsonValidationErrors(['usuario_id']);
        $this->assertStringContainsString('incidencia', $response->json('errors.usuario_id.0'));

        $this->assertSame(EstadoPrestamo::Aprobado, $prestamo->fresh()->estado);
        $this->assertSame(Equipo::ESTADO_DISPONIBLE, $equipo->fresh()->estado);
    }
}

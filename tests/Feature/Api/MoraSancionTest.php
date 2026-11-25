<?php

namespace Tests\Feature\Api;

use App\Enums\CondicionEquipo;
use App\Models\Equipo;
use App\Models\Prestamo;
use App\Models\Sancion;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\ConfiguraParametros;
use Tests\TestCase;

/**
 * RN-04 — Sanción escalonada por mora y bloqueo de nuevas solicitudes.
 */
class MoraSancionTest extends TestCase
{
    use ConfiguraParametros;
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

    /**
     * @return array{0: Prestamo, 1: User}
     */
    private function prestamoEntregadoVencidoHace(int $dias): array
    {
        $solicitante = User::factory()->create();
        $solicitante->assignRole('estudiante');

        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_EN_PRESTAMO]);

        $prestamo = Prestamo::factory()->entregado()->create([
            'usuario_id' => $solicitante->id,
            'equipo_id' => $equipo->id,
            'fecha_entrega_real' => now()->subDays($dias + 2),
            'fecha_devolucion_estimada' => now()->subDays($dias),
        ]);

        return [$prestamo, $solicitante];
    }

    private function devolver(Prestamo $prestamo, ?string $fecha = null): TestResponse
    {
        $payload = ['condicion_devolucion' => CondicionEquipo::Bueno->value];

        if ($fecha !== null) {
            $payload['fecha_devolucion_real'] = $fecha;
        }

        return $this->actingAs($this->personal(), 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/devolucion", $payload);
    }

    public function test_una_devolucion_en_fecha_no_genera_sancion(): void
    {
        $solicitante = User::factory()->create();
        $solicitante->assignRole('estudiante');
        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_EN_PRESTAMO]);
        $prestamo = Prestamo::factory()->entregado()->create([
            'usuario_id' => $solicitante->id,
            'equipo_id' => $equipo->id,
            'fecha_entrega_real' => now()->subDay(),
            'fecha_devolucion_estimada' => now()->addDay(),
        ]);

        $this->devolver($prestamo)->assertStatus(200);

        $this->assertSame(0, Sancion::where('user_id', $solicitante->id)->count());
    }

    public function test_un_dia_de_retraso_sanciona_tres_dias(): void
    {
        [$prestamo, $solicitante] = $this->prestamoEntregadoVencidoHace(1);

        $this->devolver($prestamo)->assertStatus(200);

        $sancion = Sancion::where('user_id', $solicitante->id)->firstOrFail();

        $this->assertSame(Sancion::ESTADO_ACTIVA, $sancion->estado);
        $this->assertSame(1, $sancion->dias_retraso);
        $this->assertSame(3, $sancion->dias_sancion);
        $this->assertSame($prestamo->id, $sancion->prestamo_id);
        // fecha_fin = inicio + 3 días
        $this->assertTrue($sancion->fecha_fin->between(now()->addDays(2)->subHour(), now()->addDays(3)->addHour()));
    }

    public function test_dos_dias_de_retraso_siguen_sancionando_tres_dias(): void
    {
        [$prestamo, $solicitante] = $this->prestamoEntregadoVencidoHace(2);

        $this->devolver($prestamo)->assertStatus(200);

        $sancion = Sancion::where('user_id', $solicitante->id)->firstOrFail();
        $this->assertSame(2, $sancion->dias_retraso);
        $this->assertSame(3, $sancion->dias_sancion);
    }

    public function test_seis_dias_de_retraso_sancionan_quince_dias(): void
    {
        [$prestamo, $solicitante] = $this->prestamoEntregadoVencidoHace(6);

        $this->devolver($prestamo)->assertStatus(200);

        $sancion = Sancion::where('user_id', $solicitante->id)->firstOrFail();
        $this->assertSame(6, $sancion->dias_retraso);
        $this->assertSame(15, $sancion->dias_sancion);
    }

    public function test_la_escala_de_mora_es_parametrizable(): void
    {
        $this->parametro('mora.escala', [
            ['desde' => 1, 'hasta' => null, 'dias_sancion' => 10],
        ]);

        [$prestamo, $solicitante] = $this->prestamoEntregadoVencidoHace(1);

        $this->devolver($prestamo)->assertStatus(200);

        $sancion = Sancion::where('user_id', $solicitante->id)->firstOrFail();
        $this->assertSame(10, $sancion->dias_sancion);
    }

    public function test_con_sancion_activa_no_se_puede_solicitar(): void
    {
        [$prestamo, $solicitante] = $this->prestamoEntregadoVencidoHace(1);

        $this->devolver($prestamo)->assertStatus(200);

        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_DISPONIBLE]);

        $response = $this->actingAs($solicitante, 'sanctum')->postJson('/api/prestamos', [
            'equipo_id' => $equipo->id,
            'motivo' => 'Solicitud durante la sanción',
            'fecha_inicio' => now()->addHour()->format('Y-m-d H:i:s'),
            'fecha_devolucion_estimada' => now()->addDays(2)->format('Y-m-d H:i:s'),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['usuario_id']);
        $this->assertStringContainsString('sanción activa', $response->json('errors.usuario_id.0'));
    }
}

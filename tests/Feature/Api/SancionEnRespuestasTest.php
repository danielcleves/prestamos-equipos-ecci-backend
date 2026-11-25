<?php

namespace Tests\Feature\Api;

use App\Enums\CondicionEquipo;
use App\Models\Equipo;
use App\Models\Prestamo;
use App\Models\Sancion;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RN-04 / KAN-293 — la sanción por mora expuesta en los recursos del usuario
 * ('sancion_activa') y en la respuesta de la devolución ('sancion').
 *
 * El reloj se fija al mediodía de Bogotá (17:00 UTC) para que el conteo de días
 * restantes no dependa de la hora a la que corra la suite.
 */
class SancionEnRespuestasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-12-09 17:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function personal(string $rol = 'encargado'): User
    {
        $usuario = User::factory()->create();
        $usuario->assignRole($rol);

        return $usuario;
    }

    private function sancion(User $usuario, Carbon $inicio, Carbon $fin, string $estado = Sancion::ESTADO_ACTIVA): Sancion
    {
        return Sancion::create([
            'user_id' => $usuario->id,
            'motivo' => Sancion::MOTIVO_MORA,
            'dias_retraso' => 1,
            'dias_sancion' => 3,
            'fecha_inicio' => $inicio,
            'fecha_fin' => $fin,
            'estado' => $estado,
        ]);
    }

    private function prestamoEntregado(User $solicitante, Carbon $estimada): Prestamo
    {
        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_EN_PRESTAMO]);

        return Prestamo::factory()->entregado()->create([
            'usuario_id' => $solicitante->id,
            'equipo_id' => $equipo->id,
            'fecha_inicio' => $estimada->copy()->subDays(3),
            'fecha_entrega_real' => $estimada->copy()->subDays(3),
            'fecha_devolucion_estimada' => $estimada,
        ]);
    }

    // --- UserResource::sancion_activa ---

    public function test_un_usuario_sin_sanciones_expone_sancion_activa_null(): void
    {
        $admin = $this->personal('admin');
        $solicitante = $this->personal('estudiante');

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/usuarios/{$solicitante->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $solicitante->id)
            ->assertJsonPath('data.sancion_activa', null);
    }

    public function test_un_usuario_con_sancion_vigente_expone_su_sancion_activa(): void
    {
        $admin = $this->personal('admin');
        $solicitante = $this->personal('estudiante');
        $sancion = $this->sancion($solicitante, now(), now()->addDays(3));

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/usuarios/{$solicitante->id}")
            ->assertOk()
            ->assertJsonPath('data.sancion_activa.id', $sancion->id)
            ->assertJsonPath('data.sancion_activa.motivo', Sancion::MOTIVO_MORA)
            ->assertJsonPath('data.sancion_activa.dias_restantes', 3)
            ->assertJsonPath('data.sancion_activa.vigente', true)
            ->assertJsonPath(
                'data.sancion_activa.fecha_fin',
                $sancion->fecha_fin->copy()->setTimezone('America/Bogota')->toIso8601String()
            );
    }

    public function test_una_sancion_vencida_no_se_expone_como_activa(): void
    {
        $admin = $this->personal('admin');
        $solicitante = $this->personal('estudiante');

        // Sigue guardada como 'activa' (la marca 'cumplida' la hace la
        // elegibilidad), pero su fecha_fin ya pasó.
        $sancion = $this->sancion($solicitante, now()->subDays(5), now()->subDays(2));

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/usuarios/{$solicitante->id}")
            ->assertOk()
            ->assertJsonPath('data.sancion_activa', null);

        $this->assertSame(Sancion::ESTADO_ACTIVA, $sancion->fresh()->estado);
    }

    public function test_el_listado_de_usuarios_expone_sancion_activa_en_cada_fila(): void
    {
        $admin = $this->personal('admin');
        $sancionado = $this->personal('estudiante');
        $limpio = $this->personal('estudiante');
        $this->sancion($sancionado, now(), now()->addDays(3));

        $filas = collect(
            $this->actingAs($admin, 'sanctum')->getJson('/api/usuarios?per_page=100')->assertOk()->json('data')
        );

        $this->assertSame(3, $filas->count());
        $this->assertSame(3, $filas->firstWhere('id', $sancionado->id)['sancion_activa']['dias_restantes']);
        $this->assertNull($filas->firstWhere('id', $limpio->id)['sancion_activa']);
        $this->assertNull($filas->firstWhere('id', $admin->id)['sancion_activa']);
    }

    public function test_el_endpoint_me_expone_la_sancion_del_usuario_autenticado(): void
    {
        $solicitante = $this->personal('estudiante');
        $this->sancion($solicitante, now(), now()->addDays(1));

        $this->actingAs($solicitante, 'sanctum')
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.sancion_activa.dias_restantes', 1)
            ->assertJsonPath('user.sancion_activa.motivo', Sancion::MOTIVO_MORA);
    }

    // --- POST /api/prestamos/{prestamo}/devolucion ---

    public function test_una_devolucion_a_tiempo_devuelve_sancion_null(): void
    {
        $solicitante = $this->personal('estudiante');
        $prestamo = $this->prestamoEntregado($solicitante, now()->addDay());

        $this->actingAs($this->personal(), 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/devolucion", [
                'condicion_devolucion' => CondicionEquipo::Bueno->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.estado', 'devuelto')
            ->assertJsonPath('sancion', null);

        $this->assertSame(0, Sancion::where('user_id', $solicitante->id)->count());
    }

    public function test_una_devolucion_con_mora_devuelve_la_sancion_generada(): void
    {
        $solicitante = $this->personal('estudiante');
        $prestamo = $this->prestamoEntregado($solicitante, now()->subDay());

        $respuesta = $this->actingAs($this->personal(), 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/devolucion", [
                'condicion_devolucion' => CondicionEquipo::Bueno->value,
            ]);

        $respuesta->assertOk()
            ->assertJsonPath('data.estado', 'devuelto')
            ->assertJsonPath('sancion.user_id', $solicitante->id)
            ->assertJsonPath('sancion.prestamo_id', $prestamo->id)
            ->assertJsonPath('sancion.motivo', Sancion::MOTIVO_MORA)
            ->assertJsonPath('sancion.dias_retraso', 1)
            ->assertJsonPath('sancion.dias_sancion', 3)
            ->assertJsonPath('sancion.dias_restantes', 3)
            ->assertJsonPath('sancion.estado', Sancion::ESTADO_ACTIVA)
            ->assertJsonPath('sancion.vigente', true);

        // La sanción devuelta es la que quedó guardada.
        $sancion = Sancion::where('prestamo_id', $prestamo->id)->firstOrFail();
        $this->assertSame($sancion->id, $respuesta->json('sancion.id'));
        $this->assertTrue($sancion->fecha_fin->equalTo(now()->addDays(3)));
    }

    public function test_devolver_antes_de_la_fecha_estimada_del_mismo_dia_no_genera_sancion(): void
    {
        $solicitante = $this->personal('estudiante');
        // Estimada hoy un poco más tarde: la devolución entra a tiempo.
        $prestamo = $this->prestamoEntregado($solicitante, now()->addHours(2));

        $this->actingAs($this->personal(), 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/devolucion", [
                'condicion_devolucion' => CondicionEquipo::Bueno->value,
            ])
            ->assertOk()
            ->assertJsonPath('sancion', null);
    }
}

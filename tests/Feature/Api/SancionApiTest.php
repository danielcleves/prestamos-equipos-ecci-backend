<?php

namespace Tests\Feature\Api;

use App\Models\Sancion;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RN-04 / KAN-293 — API de consulta de sanciones por mora.
 *
 * GET /api/usuarios/{usuario}/sanciones es de solo lectura y para el personal
 * (admin y encargado). La definición de «sanción activa» es la misma que usa
 * ElegibilidadService: estado 'activa' y fecha_fin no vencida
 * (Sancion::estaVigente / scopeVigentes).
 *
 * El reloj se fija al mediodía de Bogotá (17:00 UTC) para que el conteo de días
 * restantes no dependa de la hora a la que corra la suite.
 */
class SancionApiTest extends TestCase
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

    private function estudiante(): User
    {
        return $this->personal('estudiante');
    }

    /**
     * Crea una sanción con el rango por defecto de las pruebas: empieza ayer.
     */
    private function sancion(User $usuario, array $atributos = []): Sancion
    {
        $inicio = $atributos['fecha_inicio'] ?? now()->subDay();

        return Sancion::create(array_merge([
            'user_id' => $usuario->id,
            'motivo' => Sancion::MOTIVO_MORA,
            'dias_retraso' => 1,
            'dias_sancion' => 3,
            'fecha_inicio' => $inicio,
            'fecha_fin' => $inicio->copy()->addDays(3),
            'estado' => Sancion::ESTADO_ACTIVA,
        ], $atributos));
    }

    // --- Autorización ---

    public function test_admin_puede_consultar_las_sanciones_de_un_usuario(): void
    {
        $admin = $this->personal('admin');
        $solicitante = $this->estudiante();
        $this->sancion($solicitante);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/usuarios/{$solicitante->id}/sanciones")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'user_id', 'motivo', 'dias_restantes', 'fecha_fin', 'estado', 'vigente']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    }

    public function test_encargado_puede_consultar_las_sanciones_de_un_usuario(): void
    {
        $encargado = $this->personal('encargado');
        $solicitante = $this->estudiante();

        $this->actingAs($encargado, 'sanctum')
            ->getJson("/api/usuarios/{$solicitante->id}/sanciones")
            ->assertOk();
    }

    public function test_un_solicitante_no_puede_consultar_sanciones(): void
    {
        $solicitante = $this->estudiante();

        $this->actingAs($solicitante, 'sanctum')
            ->getJson("/api/usuarios/{$solicitante->id}/sanciones")
            ->assertStatus(403);
    }

    public function test_sin_autenticar_no_se_pueden_consultar_sanciones(): void
    {
        $solicitante = $this->estudiante();

        $this->getJson("/api/usuarios/{$solicitante->id}/sanciones")
            ->assertStatus(401);
    }

    public function test_un_usuario_inexistente_devuelve_404(): void
    {
        $admin = $this->personal('admin');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/usuarios/999999/sanciones')
            ->assertStatus(404);
    }

    // --- Contenido ---

    public function test_un_usuario_sin_sanciones_devuelve_una_lista_vacia(): void
    {
        $admin = $this->personal('admin');
        $solicitante = $this->estudiante();

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/usuarios/{$solicitante->id}/sanciones")
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_una_sancion_activa_expone_los_dias_restantes(): void
    {
        $admin = $this->personal('admin');
        $solicitante = $this->estudiante();

        // Vence en 3 días calendario: hasta el 12 de diciembre.
        $sancion = $this->sancion($solicitante, [
            'fecha_inicio' => now(),
            'fecha_fin' => now()->addDays(3),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/usuarios/{$solicitante->id}/sanciones")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $sancion->id)
            ->assertJsonPath('data.0.estado', Sancion::ESTADO_ACTIVA)
            ->assertJsonPath('data.0.motivo', Sancion::MOTIVO_MORA)
            ->assertJsonPath('data.0.dias_sancion', 3)
            ->assertJsonPath('data.0.dias_restantes', 3)
            ->assertJsonPath('data.0.vigente', true)
            ->assertJsonPath('data.0.fecha_fin', $sancion->fecha_fin->copy()->setTimezone('America/Bogota')->toIso8601String());
    }

    public function test_la_sancion_que_vence_hoy_cuenta_un_dia_restante(): void
    {
        $admin = $this->personal('admin');
        $solicitante = $this->estudiante();

        // Borde: vence hoy a las 14:00 de Bogotá, dos horas después del reloj
        // de la prueba. Sigue bloqueando, así que queda 1 día.
        $this->sancion($solicitante, [
            'fecha_inicio' => now()->subDays(3),
            'fecha_fin' => now()->addHours(2),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/usuarios/{$solicitante->id}/sanciones")
            ->assertOk()
            ->assertJsonPath('data.0.vigente', true)
            ->assertJsonPath('data.0.dias_restantes', 1);
    }

    public function test_una_sancion_vencida_no_aparece_como_vigente(): void
    {
        $admin = $this->personal('admin');
        $solicitante = $this->estudiante();

        $sancion = $this->sancion($solicitante, [
            'fecha_inicio' => now()->subDays(5),
            'fecha_fin' => now()->subDays(2),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/usuarios/{$solicitante->id}/sanciones")
            ->assertOk()
            ->assertJsonPath('data.0.id', $sancion->id)
            ->assertJsonPath('data.0.vigente', false)
            ->assertJsonPath('data.0.dias_restantes', 0);

        // La consulta es de solo lectura: no adelanta el paso a 'cumplida',
        // que sigue siendo responsabilidad de ElegibilidadService.
        $this->assertSame(Sancion::ESTADO_ACTIVA, $sancion->fresh()->estado);
    }

    public function test_el_listado_pone_las_sanciones_activas_primero(): void
    {
        $admin = $this->personal('admin');
        $solicitante = $this->estudiante();

        // La vigente se crea primero para que un simple ORDER BY id las
        // devuelva al revés.
        $vigente = $this->sancion($solicitante, [
            'fecha_inicio' => now(),
            'fecha_fin' => now()->addDays(3),
        ]);
        $cumplida = $this->sancion($solicitante, [
            'fecha_inicio' => now()->subDays(10),
            'fecha_fin' => now()->subDays(7),
            'estado' => Sancion::ESTADO_CUMPLIDA,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/usuarios/{$solicitante->id}/sanciones")
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', $vigente->id)
            ->assertJsonPath('data.0.vigente', true)
            ->assertJsonPath('data.1.id', $cumplida->id)
            ->assertJsonPath('data.1.vigente', false);
    }

    public function test_el_listado_de_sanciones_esta_paginado(): void
    {
        $admin = $this->personal('admin');
        $solicitante = $this->estudiante();

        foreach (range(1, 4) as $dias) {
            $this->sancion($solicitante, [
                'fecha_inicio' => now()->subDays($dias + 5),
                'fecha_fin' => now()->subDays($dias),
            ]);
        }

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/usuarios/{$solicitante->id}/sanciones?per_page=3")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.per_page', 3)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_solo_devuelve_las_sanciones_del_usuario_consultado(): void
    {
        $admin = $this->personal('admin');
        $solicitante = $this->estudiante();
        $otro = $this->estudiante();

        $propia = $this->sancion($solicitante);
        $this->sancion($otro);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/usuarios/{$solicitante->id}/sanciones")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $propia->id)
            ->assertJsonPath('data.0.user_id', $solicitante->id);
    }
}

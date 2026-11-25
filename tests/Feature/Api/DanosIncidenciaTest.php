<?php

namespace Tests\Feature\Api;

use App\Enums\CondicionEquipo;
use App\Models\Equipo;
use App\Models\Incidencia;
use App\Models\Prestamo;
use App\Models\RevisionEquipo;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\ConfiguraParametros;
use Tests\TestCase;

/**
 * RN-08 — Daños en la devolución, incidencia y restricción temporal.
 */
class DanosIncidenciaTest extends TestCase
{
    use ConfiguraParametros;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function personal(string $rol = 'encargado'): User
    {
        $user = User::factory()->create();
        $user->assignRole($rol);

        return $user;
    }

    private function solicitante(): User
    {
        return $this->personal('estudiante');
    }

    /**
     * @return array{0: Prestamo, 1: Equipo}
     */
    private function prestamoEntregado(?User $solicitante = null): array
    {
        $solicitante ??= $this->solicitante();
        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_EN_PRESTAMO]);
        $prestamo = Prestamo::factory()->entregado()->create([
            'usuario_id' => $solicitante->id,
            'equipo_id' => $equipo->id,
            'fecha_entrega_real' => now()->subDay(),
            'fecha_devolucion_estimada' => now()->addDay(),
        ]);

        return [$prestamo, $equipo];
    }

    private function devolverConDanos(Prestamo $prestamo): TestResponse
    {
        return $this->actingAs($this->personal(), 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/devolucion", [
                'condicion_devolucion' => CondicionEquipo::ConDanos->value,
                'observaciones' => 'Pantalla rota en la esquina inferior.',
            ]);
    }

    private function payloadSolicitud(): array
    {
        return [
            'equipo_id' => Equipo::factory()->create(['estado' => Equipo::ESTADO_DISPONIBLE])->id,
            'motivo' => 'Solicitud posterior a la devolución',
            'fecha_inicio' => now()->addHour()->format('Y-m-d H:i:s'),
            'fecha_devolucion_estimada' => now()->addDays(2)->format('Y-m-d H:i:s'),
        ];
    }

    public function test_una_devolucion_con_danos_abre_incidencia_y_la_enlaza_con_la_revision(): void
    {
        [$prestamo, $equipo] = $this->prestamoEntregado();

        $this->devolverConDanos($prestamo)->assertStatus(200);

        $incidencia = Incidencia::where('prestamo_id', $prestamo->id)->firstOrFail();
        $revision = RevisionEquipo::where('prestamo_id', $prestamo->id)->firstOrFail();

        $this->assertSame(Incidencia::ESTADO_ABIERTA, $incidencia->estado);
        $this->assertSame($prestamo->usuario_id, $incidencia->user_id);
        $this->assertSame($equipo->id, $incidencia->equipo_id);
        $this->assertSame($revision->id, $incidencia->revision_id);
        $this->assertSame($incidencia->id, $revision->fresh()->incidencia_id);
        $this->assertStringContainsString('Pantalla rota', $incidencia->descripcion);
        $this->assertTrue($incidencia->restringe_hasta->between(now()->addDays(6), now()->addDays(8)));
        $this->assertSame(Equipo::ESTADO_MANTENIMIENTO, $equipo->fresh()->estado);
    }

    public function test_una_devolucion_en_buen_estado_no_abre_incidencia(): void
    {
        [$prestamo] = $this->prestamoEntregado();

        $this->actingAs($this->personal(), 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/devolucion", [
                'condicion_devolucion' => CondicionEquipo::Bueno->value,
            ])
            ->assertStatus(200);

        $this->assertSame(0, Incidencia::count());
    }

    public function test_el_solicitante_con_incidencia_abierta_no_puede_solicitar_otro_equipo(): void
    {
        $solicitante = $this->solicitante();
        [$prestamo] = $this->prestamoEntregado($solicitante);

        $this->devolverConDanos($prestamo)->assertStatus(200);

        $response = $this->actingAs($solicitante, 'sanctum')
            ->postJson('/api/prestamos', $this->payloadSolicitud());

        $response->assertStatus(422)->assertJsonValidationErrors(['usuario_id']);
        $this->assertStringContainsString('incidencia abierta', $response->json('errors.usuario_id.0'));
    }

    public function test_cerrar_la_incidencia_desbloquea_al_solicitante(): void
    {
        $solicitante = $this->solicitante();
        [$prestamo] = $this->prestamoEntregado($solicitante);
        $this->devolverConDanos($prestamo)->assertStatus(200);

        $incidencia = Incidencia::where('prestamo_id', $prestamo->id)->firstOrFail();
        $encargado = $this->personal('encargado');

        $this->actingAs($encargado, 'sanctum')
            ->patchJson("/api/incidencias/{$incidencia->id}/cerrar")
            ->assertStatus(200)
            ->assertJsonPath('data.estado', Incidencia::ESTADO_CERRADA)
            ->assertJsonPath('data.resuelta_por', $encargado->id);

        $this->assertNotNull($incidencia->fresh()->fecha_cierre);

        $this->actingAs($solicitante, 'sanctum')
            ->postJson('/api/prestamos', $this->payloadSolicitud())
            ->assertStatus(201);
    }

    public function test_solo_el_personal_administrativo_cierra_incidencias(): void
    {
        $solicitante = $this->solicitante();
        [$prestamo] = $this->prestamoEntregado($solicitante);
        $this->devolverConDanos($prestamo)->assertStatus(200);

        $incidencia = Incidencia::where('prestamo_id', $prestamo->id)->firstOrFail();

        $this->actingAs($solicitante, 'sanctum')
            ->patchJson("/api/incidencias/{$incidencia->id}/cerrar")
            ->assertStatus(403);

        $this->assertSame(Incidencia::ESTADO_ABIERTA, $incidencia->fresh()->estado);
    }

    public function test_una_incidencia_con_restriccion_vencida_ya_no_bloquea(): void
    {
        $solicitante = $this->solicitante();
        [$prestamo] = $this->prestamoEntregado($solicitante);
        $this->devolverConDanos($prestamo)->assertStatus(200);

        Incidencia::where('prestamo_id', $prestamo->id)->update([
            'restringe_hasta' => now()->subDay(),
        ]);

        $this->actingAs($solicitante, 'sanctum')
            ->postJson('/api/prestamos', $this->payloadSolicitud())
            ->assertStatus(201);
    }

    public function test_los_dias_de_restriccion_son_parametrizables(): void
    {
        $this->parametro('danos.dias_restriccion', 3);
        [$prestamo] = $this->prestamoEntregado();

        $this->devolverConDanos($prestamo)->assertStatus(200);

        $incidencia = Incidencia::where('prestamo_id', $prestamo->id)->firstOrFail();
        $this->assertTrue($incidencia->restringe_hasta->between(now()->addDays(2), now()->addDays(4)));
    }

    public function test_el_personal_lista_y_filtra_incidencias(): void
    {
        [$prestamo] = $this->prestamoEntregado();
        $this->devolverConDanos($prestamo)->assertStatus(200);

        $encargado = $this->personal();

        $this->actingAs($encargado, 'sanctum')
            ->getJson('/api/incidencias')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.estado', Incidencia::ESTADO_ABIERTA);

        $this->actingAs($encargado, 'sanctum')
            ->getJson('/api/incidencias?estado=abierta')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1);

        $this->actingAs($encargado, 'sanctum')
            ->getJson('/api/incidencias?estado=cerrada')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 0);
    }

    public function test_no_se_cierra_dos_veces_la_misma_incidencia(): void
    {
        [$prestamo] = $this->prestamoEntregado();
        $this->devolverConDanos($prestamo)->assertStatus(200);

        $incidencia = Incidencia::where('prestamo_id', $prestamo->id)->firstOrFail();
        $encargado = $this->personal();

        $this->actingAs($encargado, 'sanctum')
            ->patchJson("/api/incidencias/{$incidencia->id}/cerrar")
            ->assertStatus(200);

        $this->actingAs($encargado, 'sanctum')
            ->patchJson("/api/incidencias/{$incidencia->id}/cerrar")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['estado']);
    }
}

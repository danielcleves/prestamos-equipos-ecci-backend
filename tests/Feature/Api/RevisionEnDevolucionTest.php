<?php

namespace Tests\Feature\Api;

use App\Enums\CondicionEquipo;
use App\Enums\EstadoPrestamo;
use App\Models\Equipo;
use App\Models\Prestamo;
use App\Models\RevisionEquipo;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HU-12 / KAN-127: integracion de la revision con el flujo de devolucion.
 */
class RevisionEnDevolucionTest extends TestCase
{
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

    private function prestamoEntregado(?Equipo $equipo = null): Prestamo
    {
        $equipo ??= Equipo::factory()->create(['estado' => Equipo::ESTADO_EN_PRESTAMO]);

        return Prestamo::factory()->entregado()->create([
            'equipo_id' => $equipo->id,
            'fecha_entrega_real' => now()->subDay(),
        ]);
    }

    public function test_la_devolucion_en_buen_estado_crea_la_revision(): void
    {
        $encargado = $this->personal();
        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_EN_PRESTAMO]);
        $prestamo = $this->prestamoEntregado($equipo);

        $response = $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/devolucion", [
                'condicion_devolucion' => CondicionEquipo::Bueno->value,
                'observaciones' => 'Todo completo',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.revision.prestamo_id', $prestamo->id)
            ->assertJsonPath('data.revision.equipo_id', $equipo->id)
            ->assertJsonPath('data.revision.condicion', CondicionEquipo::Bueno->value)
            ->assertJsonPath('data.revision.condicion_etiqueta', 'Bueno')
            ->assertJsonPath('data.revision.tiene_danos', false)
            ->assertJsonPath('data.revision.observaciones', 'Todo completo')
            ->assertJsonPath('data.revision.revisado_por', $encargado->id)
            ->assertJsonPath('data.revision.incidencia_id', null);

        $this->assertDatabaseCount('revisiones_equipo', 1);

        $revision = RevisionEquipo::where('prestamo_id', $prestamo->id)->firstOrFail();
        $this->assertSame($equipo->id, $revision->equipo_id);
        $this->assertSame(CondicionEquipo::Bueno, $revision->condicion);
        $this->assertFalse($revision->tiene_danos);
        $this->assertSame($encargado->id, $revision->revisado_por);
    }

    public function test_la_devolucion_con_danos_crea_la_revision_con_novedad(): void
    {
        $admin = $this->personal('admin');
        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_EN_PRESTAMO]);
        $prestamo = $this->prestamoEntregado($equipo);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/devolucion", [
                'condicion_devolucion' => CondicionEquipo::ConDanos->value,
                'observaciones' => 'Tapa rayada y una bisagra suelta',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.revision.condicion', CondicionEquipo::ConDanos->value)
            ->assertJsonPath('data.revision.tiene_danos', true)
            ->assertJsonPath('data.revision.observaciones', 'Tapa rayada y una bisagra suelta');

        $revision = RevisionEquipo::where('prestamo_id', $prestamo->id)->firstOrFail();
        $this->assertTrue($revision->tiene_danos);
    }

    public function test_la_revision_queda_alineada_con_las_columnas_del_prestamo(): void
    {
        $encargado = $this->personal();
        $prestamo = $this->prestamoEntregado();

        $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/devolucion", [
                'condicion_devolucion' => CondicionEquipo::RequiereMantenimiento->value,
                'observaciones' => 'Bateria agotada',
            ])
            ->assertStatus(200);

        // Las columnas de 'prestamos' se mantienen como espejo de la revision
        // (ver docs/decisiones.md): ambas fuentes deben coincidir.
        $prestamo->refresh();
        $revision = $prestamo->revision;

        $this->assertNotNull($revision);
        $this->assertSame($prestamo->condicion_devolucion, $revision->condicion);
        $this->assertSame($prestamo->observaciones_devolucion, $revision->observaciones);
        $this->assertSame($prestamo->recibido_por, $revision->revisado_por);
        $this->assertSame(
            $prestamo->fecha_devolucion_real->format('Y-m-d H:i:s'),
            $revision->fecha_revision->format('Y-m-d H:i:s')
        );
    }

    public function test_la_fecha_de_revision_respeta_la_fecha_de_devolucion_enviada(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-17 18:00:00', 'America/Bogota'));

        try {
            $encargado = $this->personal();
            $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_EN_PRESTAMO]);
            $prestamo = Prestamo::factory()->entregado()->create([
                'equipo_id' => $equipo->id,
                'fecha_entrega_real' => '2026-11-17 17:00:00', // 12:00 Bogota
            ]);

            $this->actingAs($encargado, 'sanctum')
                ->postJson("/api/prestamos/{$prestamo->id}/devolucion", [
                    'condicion_devolucion' => CondicionEquipo::Bueno->value,
                    'fecha_devolucion_real' => '2026-11-17 16:30:00',
                ])
                ->assertStatus(200)
                ->assertJsonPath('data.revision.fecha_revision', '2026-11-17T16:30:00-05:00');

            $revision = RevisionEquipo::where('prestamo_id', $prestamo->id)->firstOrFail();
            $this->assertSame('2026-11-17 21:30:00', $revision->fecha_revision->format('Y-m-d H:i:s'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_la_clave_revision_existe_y_es_nula_mientras_no_haya_devolucion(): void
    {
        $encargado = $this->personal();
        $prestamo = $this->prestamoEntregado();

        $response = $this->actingAs($encargado, 'sanctum')
            ->getJson("/api/prestamos/{$prestamo->id}");

        $response->assertStatus(200);
        $this->assertArrayHasKey('revision', $response->json('data'));
        $this->assertNull($response->json('data.revision'));
    }

    public function test_el_solicitante_ve_la_condicion_pero_no_las_observaciones_de_la_revision(): void
    {
        $encargado = $this->personal();
        $solicitante = User::factory()->create();
        $solicitante->assignRole('usuario');

        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_EN_PRESTAMO]);
        $prestamo = Prestamo::factory()->entregado()->create([
            'usuario_id' => $solicitante->id,
            'equipo_id' => $equipo->id,
            'fecha_entrega_real' => now()->subDay(),
        ]);

        $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/devolucion", [
                'condicion_devolucion' => CondicionEquipo::ConDanos->value,
                'observaciones' => 'Observacion reservada al personal',
            ])
            ->assertStatus(200);

        // El personal si ve las observaciones de la revision
        $this->actingAs($encargado, 'sanctum')
            ->getJson("/api/prestamos/{$prestamo->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.revision.observaciones', 'Observacion reservada al personal');

        // El solicitante ve la condicion, pero nunca las observaciones
        $respuestaSolicitante = $this->actingAs($solicitante, 'sanctum')
            ->getJson("/api/prestamos/{$prestamo->id}");

        $respuestaSolicitante->assertStatus(200)
            ->assertJsonPath('data.revision.condicion', CondicionEquipo::ConDanos->value)
            ->assertJsonPath('data.revision.condicion_etiqueta', 'Con daños')
            ->assertJsonPath('data.revision.tiene_danos', true);

        $this->assertArrayNotHasKey('observaciones', $respuestaSolicitante->json('data.revision'));
    }

    public function test_no_se_crea_revision_si_el_prestamo_no_esta_entregado(): void
    {
        $encargado = $this->personal();
        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_EN_PRESTAMO]);
        $prestamo = Prestamo::factory()->aprobado()->create(['equipo_id' => $equipo->id]);

        $response = $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/devolucion", [
                'condicion_devolucion' => CondicionEquipo::Bueno->value,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['estado']);

        // El mensaje sigue siendo el de la maquina de estados, no el de la revision
        $this->assertSame(
            'El préstamo está en estado Aprobado y no puede pasar a Devuelto.',
            $response->json('errors.estado.0')
        );

        $this->assertDatabaseCount('revisiones_equipo', 0);
    }

    public function test_no_se_crea_revision_si_el_equipo_no_esta_en_prestamo(): void
    {
        $encargado = $this->personal();
        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_DISPONIBLE]);
        $prestamo = Prestamo::factory()->entregado()->create(['equipo_id' => $equipo->id]);

        $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/devolucion", [
                'condicion_devolucion' => CondicionEquipo::Bueno->value,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['equipo']);

        $this->assertDatabaseCount('revisiones_equipo', 0);
    }

    public function test_no_se_crea_revision_si_falla_la_validacion_de_fecha(): void
    {
        $encargado = $this->personal();
        $prestamo = $this->prestamoEntregado();

        $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/devolucion", [
                'condicion_devolucion' => CondicionEquipo::Bueno->value,
                'fecha_devolucion_real' => now()->subDays(5)->toDateTimeString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fecha_devolucion_real']);

        $this->assertDatabaseCount('revisiones_equipo', 0);
        $this->assertSame(EstadoPrestamo::Entregado, $prestamo->fresh()->estado);
    }

    public function test_no_se_crea_revision_si_faltan_las_observaciones_obligatorias(): void
    {
        $encargado = $this->personal();
        $prestamo = $this->prestamoEntregado();

        $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/devolucion", [
                'condicion_devolucion' => CondicionEquipo::ConDanos->value,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['observaciones']);

        $this->assertDatabaseCount('revisiones_equipo', 0);
    }

    public function test_una_segunda_devolucion_no_duplica_la_revision(): void
    {
        $encargado = $this->personal();
        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_EN_PRESTAMO]);
        $prestamo = $this->prestamoEntregado($equipo);

        $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/devolucion", [
                'condicion_devolucion' => CondicionEquipo::Bueno->value,
            ])
            ->assertStatus(200);

        // Segundo intento: el equipo ya volvio a 'disponible', asi que la
        // validacion del equipo salta antes que la de la maquina de estados.
        $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/devolucion", [
                'condicion_devolucion' => CondicionEquipo::ConDanos->value,
                'observaciones' => 'Segundo intento',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['equipo']);

        $this->assertDatabaseCount('revisiones_equipo', 1);
        $this->assertSame(EstadoPrestamo::Devuelto, $prestamo->fresh()->estado);
    }

    public function test_un_prestamo_ya_devuelto_con_el_equipo_en_prestamo_es_rechazado_por_el_estado(): void
    {
        $encargado = $this->personal();

        // Escenario forzado: el prestamo ya esta devuelto pero el equipo volvio
        // a 'en_prestamo' por otro prestamo. Asi se ejercita el pre-chequeo de
        // la maquina de estados sin que lo tape la validacion del equipo.
        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_EN_PRESTAMO]);
        $prestamo = Prestamo::factory()->devuelto()->create(['equipo_id' => $equipo->id]);

        $response = $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/devolucion", [
                'condicion_devolucion' => CondicionEquipo::Bueno->value,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['estado']);

        $this->assertSame(
            'El préstamo está en estado Devuelto y no puede pasar a Devuelto.',
            $response->json('errors.estado.0')
        );

        $this->assertDatabaseCount('revisiones_equipo', 0);
    }

    public function test_el_rol_usuario_no_puede_registrar_la_revision_por_la_via_de_la_devolucion(): void
    {
        $usuario = User::factory()->create();
        $usuario->assignRole('usuario');
        $prestamo = $this->prestamoEntregado();

        $this->actingAs($usuario, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/devolucion", [
                'condicion_devolucion' => CondicionEquipo::Bueno->value,
            ])
            ->assertStatus(403);

        $this->assertDatabaseCount('revisiones_equipo', 0);
    }

    public function test_sin_token_no_se_crea_revision(): void
    {
        $prestamo = $this->prestamoEntregado();

        $this->postJson("/api/prestamos/{$prestamo->id}/devolucion", [
            'condicion_devolucion' => CondicionEquipo::Bueno->value,
        ])->assertStatus(401);

        $this->assertDatabaseCount('revisiones_equipo', 0);
    }
}

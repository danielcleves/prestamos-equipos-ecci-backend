<?php

namespace Tests\Feature\Api;

use App\Enums\CondicionEquipo;
use App\Enums\EstadoPrestamo;
use App\Models\Equipo;
use App\Models\Prestamo;
use App\Models\RevisionEquipo;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * HU-12 / KAN-130: trazabilidad de las revisiones para la HU-31.
 */
class RevisionEquipoTrazabilidadTest extends TestCase
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

    public function test_el_personal_consulta_las_revisiones_de_un_equipo(): void
    {
        $encargado = $this->personal();
        $equipo = Equipo::factory()->create();

        $antigua = RevisionEquipo::factory()->bueno()->create([
            'equipo_id' => $equipo->id,
            'fecha_revision' => now()->subDays(5),
        ]);
        $reciente = RevisionEquipo::factory()->conDanos('Tapa agrietada')->create([
            'equipo_id' => $equipo->id,
            'fecha_revision' => now()->subDay(),
        ]);

        // Revision de otro equipo: no debe aparecer
        $otra = RevisionEquipo::factory()->create();

        $response = $this->actingAs($encargado, 'sanctum')
            ->getJson("/api/equipos/{$equipo->id}/revisiones");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            // Mas recientes primero
            ->assertJsonPath('data.0.id', $reciente->id)
            ->assertJsonPath('data.1.id', $antigua->id)
            ->assertJsonPath('data.0.condicion', CondicionEquipo::ConDanos->value)
            ->assertJsonPath('data.0.condicion_etiqueta', 'Con daños')
            ->assertJsonPath('data.0.tiene_danos', true)
            ->assertJsonPath('data.0.observaciones', 'Tapa agrietada');

        $ids = array_column($response->json('data'), 'id');
        $this->assertNotContains($otra->id, $ids);
    }

    public function test_la_respuesta_trae_meta_de_paginacion_y_respeta_per_page(): void
    {
        $encargado = $this->personal();
        $equipo = Equipo::factory()->create();

        RevisionEquipo::factory()->count(5)->create(['equipo_id' => $equipo->id]);

        $response = $this->actingAs($encargado, 'sanctum')
            ->getJson("/api/equipos/{$equipo->id}/revisiones?per_page=2");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 3);
    }

    public function test_per_page_invalido_no_rompe_la_paginacion(): void
    {
        $encargado = $this->personal();
        $equipo = Equipo::factory()->create();
        RevisionEquipo::factory()->count(3)->create(['equipo_id' => $equipo->id]);

        // Igual que el resto del modulo: per_page se acota a [1, 100]
        $this->actingAs($encargado, 'sanctum')
            ->getJson("/api/equipos/{$equipo->id}/revisiones?per_page=0")
            ->assertStatus(200)
            ->assertJsonPath('meta.per_page', 1);

        $this->actingAs($encargado, 'sanctum')
            ->getJson("/api/equipos/{$equipo->id}/revisiones?per_page=5000")
            ->assertStatus(200)
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_filtro_con_danos_aisla_las_revisiones_con_novedad(): void
    {
        $encargado = $this->personal();
        $equipo = Equipo::factory()->create();

        RevisionEquipo::factory()->bueno()->create(['equipo_id' => $equipo->id]);
        $conDanos = RevisionEquipo::factory()->conDanos()->create(['equipo_id' => $equipo->id]);
        $mantenimiento = RevisionEquipo::factory()->requiereMantenimiento()->create(['equipo_id' => $equipo->id]);

        $response = $this->actingAs($encargado, 'sanctum')
            ->getJson("/api/equipos/{$equipo->id}/revisiones?con_danos=1");

        $response->assertStatus(200)->assertJsonCount(2, 'data');

        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($conDanos->id, $ids);
        $this->assertContains($mantenimiento->id, $ids);

        $sinDanos = $this->actingAs($encargado, 'sanctum')
            ->getJson("/api/equipos/{$equipo->id}/revisiones?con_danos=0");

        $sinDanos->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_un_equipo_sin_revisiones_devuelve_lista_vacia(): void
    {
        $encargado = $this->personal();
        $equipo = Equipo::factory()->create();

        $this->actingAs($encargado, 'sanctum')
            ->getJson("/api/equipos/{$equipo->id}/revisiones")
            ->assertStatus(200)
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_el_rol_usuario_recibe_403(): void
    {
        $usuario = User::factory()->create();
        $usuario->assignRole('estudiante');
        $equipo = Equipo::factory()->create();
        RevisionEquipo::factory()->conDanos()->create(['equipo_id' => $equipo->id]);

        $this->actingAs($usuario, 'sanctum')
            ->getJson("/api/equipos/{$equipo->id}/revisiones")
            ->assertStatus(403)
            ->assertJsonPath('message', 'No tienes permiso para realizar esta acción.');
    }

    public function test_sin_token_recibe_401(): void
    {
        $equipo = Equipo::factory()->create();

        $this->getJson("/api/equipos/{$equipo->id}/revisiones")
            ->assertStatus(401)
            ->assertJsonPath('message', 'No autenticado.');
    }

    public function test_un_equipo_inexistente_devuelve_404(): void
    {
        $encargado = $this->personal();

        $this->actingAs($encargado, 'sanctum')
            ->getJson('/api/equipos/999999/revisiones')
            ->assertStatus(404)
            ->assertJsonPath('message', 'Recurso no encontrado.');
    }

    public function test_un_admin_tambien_puede_consultarlas(): void
    {
        $admin = $this->personal('admin');
        $equipo = Equipo::factory()->create();
        RevisionEquipo::factory()->conDanos()->create(['equipo_id' => $equipo->id]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/equipos/{$equipo->id}/revisiones")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_la_ruta_revisiones_no_colisiona_con_el_detalle_del_equipo(): void
    {
        $encargado = $this->personal();
        $equipo = Equipo::factory()->create();

        // /equipos/{id} y /equipos/{id}/revisiones deben resolver distinto
        $this->actingAs($encargado, 'sanctum')
            ->getJson("/api/equipos/{$equipo->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $equipo->id);

        $this->actingAs($encargado, 'sanctum')
            ->getJson("/api/equipos/{$equipo->id}/revisiones")
            ->assertStatus(200)
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_la_revision_se_consulta_desde_el_detalle_del_prestamo_tras_cerrarlo(): void
    {
        $encargado = $this->personal();
        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_EN_PRESTAMO]);
        $prestamo = Prestamo::factory()->entregado()->create([
            'equipo_id' => $equipo->id,
            'fecha_entrega_real' => now()->subDay(),
        ]);

        $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/devolucion", [
                'condicion_devolucion' => CondicionEquipo::ConDanos->value,
                'observaciones' => 'Carcasa golpeada',
            ])
            ->assertStatus(200);

        // El prestamo ya esta cerrado (estado final 'devuelto')
        $this->assertSame(EstadoPrestamo::Devuelto, $prestamo->fresh()->estado);
        $this->assertTrue($prestamo->fresh()->estado->isFinal());

        // La revision sigue consultable desde el detalle del prestamo
        $this->actingAs($encargado, 'sanctum')
            ->getJson("/api/prestamos/{$prestamo->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.revision.condicion', CondicionEquipo::ConDanos->value)
            ->assertJsonPath('data.revision.observaciones', 'Carcasa golpeada');

        // Y desde el equipo
        $this->actingAs($encargado, 'sanctum')
            ->getJson("/api/equipos/{$equipo->id}/revisiones")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.prestamo_id', $prestamo->id);
    }

    public function test_la_revision_se_conserva_a_traves_de_varios_ciclos_de_prestamo(): void
    {
        $encargado = $this->personal();
        $admin = $this->personal('admin');
        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_EN_PRESTAMO]);

        // Ciclo 1: devuelto con daños
        $primero = Prestamo::factory()->entregado()->create([
            'equipo_id' => $equipo->id,
            'fecha_entrega_real' => now()->subDays(5),
        ]);
        $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$primero->id}/devolucion", [
                'condicion_devolucion' => CondicionEquipo::ConDanos->value,
                'observaciones' => 'Primera novedad',
            ])->assertStatus(200);

        // El admin lo repara y lo libera
        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/equipos/{$equipo->id}/estado", ['estado' => Equipo::ESTADO_EN_PRESTAMO])
            ->assertStatus(200);

        // Ciclo 2: devuelto en buen estado
        $segundo = Prestamo::factory()->entregado()->create([
            'equipo_id' => $equipo->id,
            'fecha_entrega_real' => now()->subDay(),
        ]);
        $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$segundo->id}/devolucion", [
                'condicion_devolucion' => CondicionEquipo::Bueno->value,
            ])->assertStatus(200);

        // Ambas revisiones sobreviven, cada una ligada a su propio prestamo
        $response = $this->actingAs($encargado, 'sanctum')
            ->getJson("/api/equipos/{$equipo->id}/revisiones");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);

        $porPrestamo = collect($response->json('data'))->keyBy('prestamo_id');

        $this->assertSame(CondicionEquipo::ConDanos->value, $porPrestamo[$primero->id]['condicion']);
        $this->assertSame('Primera novedad', $porPrestamo[$primero->id]['observaciones']);
        $this->assertTrue($porPrestamo[$primero->id]['tiene_danos']);

        $this->assertSame(CondicionEquipo::Bueno->value, $porPrestamo[$segundo->id]['condicion']);
        $this->assertFalse($porPrestamo[$segundo->id]['tiene_danos']);

        // El historial de estados del equipo tambien conserva el recorrido
        $this->actingAs($encargado, 'sanctum')
            ->getJson("/api/equipos/{$equipo->id}/historial")
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 4); // creado + mantenimiento + en_prestamo + disponible
    }

    public function test_la_revision_queda_disponible_para_vincular_una_incidencia(): void
    {
        $encargado = $this->personal();
        $equipo = Equipo::factory()->create();

        $revision = RevisionEquipo::factory()->conDanos()->create(['equipo_id' => $equipo->id]);

        $this->actingAs($encargado, 'sanctum')
            ->getJson("/api/equipos/{$equipo->id}/revisiones")
            ->assertStatus(200)
            ->assertJsonPath('data.0.incidencia_id', null);

        // La HU-31 podra vincularla sin migraciones nuevas
        $revision->update(['incidencia_id' => 4321]);

        $this->actingAs($encargado, 'sanctum')
            ->getJson("/api/equipos/{$equipo->id}/revisiones")
            ->assertStatus(200)
            ->assertJsonPath('data.0.incidencia_id', 4321);
    }

    public function test_no_dispara_consultas_por_fila(): void
    {
        $encargado = $this->personal();
        $equipo = Equipo::factory()->create();
        RevisionEquipo::factory()->count(10)->create(['equipo_id' => $equipo->id]);

        $consultas = 0;
        DB::listen(function () use (&$consultas) {
            $consultas++;
        });

        $response = $this->actingAs($encargado, 'sanctum')
            ->getJson("/api/equipos/{$equipo->id}/revisiones?per_page=15");

        $response->assertStatus(200)->assertJsonCount(10, 'data');

        // 1 (token) + 1 (equipo) + 1 (roles del middleware) + 1 (conteo) + 1 (revisiones)
        // y nada por fila: el recurso no carga relaciones.
        $this->assertLessThanOrEqual(7, $consultas, "GET revisiones ejecutó {$consultas} consultas (posible N+1).");
    }
}

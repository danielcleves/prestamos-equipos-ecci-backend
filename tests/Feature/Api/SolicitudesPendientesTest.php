<?php

namespace Tests\Feature\Api;

use App\Enums\EstadoPrestamo;
use App\Models\Prestamo;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HU-07 / KAN-104: consulta de solicitudes pendientes por el personal de
 * préstamo.
 */
class SolicitudesPendientesTest extends TestCase
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

    private function solicitante(): User
    {
        $user = User::factory()->create();
        $user->assignRole('estudiante');

        return $user;
    }

    // ---------------------------------------------------------------
    // KAN-104: el endpoint
    // ---------------------------------------------------------------

    public function test_el_personal_ve_solo_las_solicitudes_pendientes(): void
    {
        $encargado = $this->personal();

        $pendiente1 = Prestamo::factory()->solicitado()->create();
        $pendiente2 = Prestamo::factory()->solicitado()->create();

        // Préstamos en otros estados: no deben aparecer
        Prestamo::factory()->aprobado()->create();
        Prestamo::factory()->rechazado()->create();
        Prestamo::factory()->entregado()->create();
        Prestamo::factory()->devuelto()->create();

        $response = $this->actingAs($encargado, 'sanctum')
            ->getJson('/api/prestamos/pendientes');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);

        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($pendiente1->id, $ids);
        $this->assertContains($pendiente2->id, $ids);

        foreach ($response->json('data') as $item) {
            $this->assertSame(EstadoPrestamo::Solicitado->value, $item['estado']);
            $this->assertSame('Solicitado', $item['estado_etiqueta']);
        }
    }

    public function test_ordena_de_la_solicitud_mas_antigua_a_la_mas_reciente(): void
    {
        $encargado = $this->personal();

        // Se crean desordenadas a propósito
        $media = Prestamo::factory()->solicitado()->create(['fecha_solicitud' => now()->subDays(2)]);
        $antigua = Prestamo::factory()->solicitado()->create(['fecha_solicitud' => now()->subDays(5)]);
        $reciente = Prestamo::factory()->solicitado()->create(['fecha_solicitud' => now()->subHour()]);

        $response = $this->actingAs($encargado, 'sanctum')
            ->getJson('/api/prestamos/pendientes');

        $response->assertStatus(200)
            // Orden de atención: primero la que lleva más tiempo esperando
            ->assertJsonPath('data.0.id', $antigua->id)
            ->assertJsonPath('data.1.id', $media->id)
            ->assertJsonPath('data.2.id', $reciente->id);
    }

    public function test_desempata_por_id_cuando_coincide_la_fecha_de_solicitud(): void
    {
        $encargado = $this->personal();
        $momento = now()->subDay();

        $primera = Prestamo::factory()->solicitado()->create(['fecha_solicitud' => $momento]);
        $segunda = Prestamo::factory()->solicitado()->create(['fecha_solicitud' => $momento]);

        $this->actingAs($encargado, 'sanctum')
            ->getJson('/api/prestamos/pendientes')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $primera->id)
            ->assertJsonPath('data.1.id', $segunda->id);
    }

    public function test_devuelve_lista_vacia_cuando_no_hay_solicitudes_pendientes(): void
    {
        $encargado = $this->personal();

        Prestamo::factory()->aprobado()->create();
        Prestamo::factory()->devuelto()->create();

        $this->actingAs($encargado, 'sanctum')
            ->getJson('/api/prestamos/pendientes')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.current_page', 1);
    }

    public function test_respeta_la_paginacion_acotada_del_modulo(): void
    {
        $encargado = $this->personal();
        Prestamo::factory()->count(5)->solicitado()->create();

        $this->actingAs($encargado, 'sanctum')
            ->getJson('/api/prestamos/pendientes?per_page=2')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.total', 5);

        // per_page se acota a [1, 100], igual que index() y activos()
        $this->actingAs($encargado, 'sanctum')
            ->getJson('/api/prestamos/pendientes?per_page=0')
            ->assertStatus(200)
            ->assertJsonPath('meta.per_page', 1);

        $this->actingAs($encargado, 'sanctum')
            ->getJson('/api/prestamos/pendientes?per_page=5000')
            ->assertStatus(200)
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_un_admin_tambien_puede_consultarlas(): void
    {
        $admin = $this->personal('admin');
        Prestamo::factory()->solicitado()->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/prestamos/pendientes')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_el_rol_usuario_recibe_403(): void
    {
        $usuario = $this->solicitante();
        Prestamo::factory()->solicitado()->create(['usuario_id' => $usuario->id]);

        $this->actingAs($usuario, 'sanctum')
            ->getJson('/api/prestamos/pendientes')
            ->assertStatus(403)
            ->assertJsonPath('message', 'No tienes permiso para realizar esta acción.');
    }

    public function test_sin_token_recibe_401(): void
    {
        Prestamo::factory()->solicitado()->create();

        $this->getJson('/api/prestamos/pendientes')
            ->assertStatus(401)
            ->assertJsonPath('message', 'No autenticado.');
    }

    public function test_la_ruta_pendientes_no_se_confunde_con_un_id(): void
    {
        $encargado = $this->personal();
        Prestamo::factory()->solicitado()->create();

        // /prestamos/pendientes debe resolver al listado, no a show('pendientes')
        $this->actingAs($encargado, 'sanctum')
            ->getJson('/api/prestamos/pendientes')
            ->assertStatus(200)
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_el_detalle_de_una_solicitud_inexistente_devuelve_404(): void
    {
        $encargado = $this->personal();

        $this->actingAs($encargado, 'sanctum')
            ->getJson('/api/prestamos/999999')
            ->assertStatus(404)
            ->assertJsonPath('message', 'Recurso no encontrado.');
    }

    public function test_el_detalle_de_una_solicitud_pendiente_sigue_saliendo_por_show(): void
    {
        $encargado = $this->personal();
        $pendiente = Prestamo::factory()->solicitado()->create();

        $this->actingAs($encargado, 'sanctum')
            ->getJson("/api/prestamos/{$pendiente->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $pendiente->id)
            ->assertJsonPath('data.estado', EstadoPrestamo::Solicitado->value);
    }
}

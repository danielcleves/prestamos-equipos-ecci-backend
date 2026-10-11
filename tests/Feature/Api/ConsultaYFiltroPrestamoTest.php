<?php

namespace Tests\Feature\Api;

use App\Enums\EstadoPrestamo;
use App\Models\Prestamo;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsultaYFiltroPrestamoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_personal_puede_filtrar_listado_por_estado_solicitado(): void
    {
        $encargado = User::factory()->create();
        $encargado->assignRole('encargado');

        Prestamo::factory()->solicitado()->count(2)->create();
        Prestamo::factory()->aprobado()->count(3)->create();
        Prestamo::factory()->rechazado()->count(1)->create();

        $response = $this->actingAs($encargado, 'sanctum')
            ->getJson('/api/prestamos?estado=solicitado');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);

        foreach ($response->json('data') as $item) {
            $this->assertSame(EstadoPrestamo::Solicitado->value, $item['estado']);
        }
    }

    public function test_personal_puede_filtrar_listado_por_estado_rechazado(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Prestamo::factory()->solicitado()->count(2)->create();
        Prestamo::factory()->aprobado()->count(2)->create();
        Prestamo::factory()->rechazado()->count(3)->create();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/prestamos?estado=rechazado');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 3);

        foreach ($response->json('data') as $item) {
            $this->assertSame(EstadoPrestamo::Rechazado->value, $item['estado']);
        }
    }

    public function test_usuario_comun_puede_filtrar_sus_propios_prestamos_por_estado(): void
    {
        $usuario1 = User::factory()->create();
        $usuario1->assignRole('usuario');

        $usuario2 = User::factory()->create();
        $usuario2->assignRole('usuario');

        // Préstamos de usuario1
        Prestamo::factory()->solicitado()->create(['usuario_id' => $usuario1->id]);
        Prestamo::factory()->rechazado()->create(['usuario_id' => $usuario1->id]);

        // Préstamos de usuario2
        Prestamo::factory()->solicitado()->create(['usuario_id' => $usuario2->id]);
        Prestamo::factory()->rechazado()->create(['usuario_id' => $usuario2->id]);

        // Usuario 1 filtra por solicitado: sólo debe ver el suyo
        $resSolicitados = $this->actingAs($usuario1, 'sanctum')
            ->getJson('/api/prestamos?estado=solicitado');

        $resSolicitados->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.usuario_id', $usuario1->id)
            ->assertJsonPath('data.0.estado', EstadoPrestamo::Solicitado->value);

        // Usuario 1 filtra por rechazado: sólo debe ver el suyo
        $resRechazados = $this->actingAs($usuario1, 'sanctum')
            ->getJson('/api/prestamos?estado=rechazado');

        $resRechazados->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.usuario_id', $usuario1->id)
            ->assertJsonPath('data.0.estado', EstadoPrestamo::Rechazado->value);
    }

    public function test_parametro_estado_vacio_no_filtra_el_listado(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Prestamo::factory()->solicitado()->count(2)->create();
        Prestamo::factory()->aprobado()->count(2)->create();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/prestamos?estado=');

        $response->assertStatus(200)
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('meta.total', 4);
    }

    public function test_estado_invalido_devuelve_422(): void
    {
        $encargado = User::factory()->create();
        $encargado->assignRole('encargado');

        $response = $this->actingAs($encargado, 'sanctum')
            ->getJson('/api/prestamos?estado=estado_inexistente');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['estado'])
            ->assertJsonPath('errors.estado.0', 'El estado especificado no es válido.');
    }

    public function test_detalle_y_listado_exponen_datos_de_gestion_aprobacion_y_rechazo(): void
    {
        $admin = User::factory()->create(['name' => 'Admin Gestor']);
        $admin->assignRole('admin');

        $solicitante = User::factory()->create(['name' => 'Estudiante Juan']);
        $solicitante->assignRole('usuario');

        $prestamoAprobado = Prestamo::factory()->aprobado()->create([
            'usuario_id' => $solicitante->id,
            'gestionado_por' => $admin->id,
        ]);

        $prestamoRechazado = Prestamo::factory()->rechazado()->create([
            'usuario_id' => $solicitante->id,
            'gestionado_por' => $admin->id,
            'motivo_rechazo' => 'Equipo no disponible en el horario requerido.',
        ]);

        // Verificación en listado (index)
        $resListado = $this->actingAs($admin, 'sanctum')->getJson('/api/prestamos');
        $resListado->assertStatus(200);

        $datos = collect($resListado->json('data'));
        $itemAprobado = $datos->firstWhere('id', $prestamoAprobado->id);
        $this->assertSame($admin->id, $itemAprobado['gestionado_por']);
        $this->assertSame('Admin Gestor', $itemAprobado['usuario_gestion']['name']);
        $this->assertNotNull($itemAprobado['fecha_aprobacion']);
        $this->assertNull($itemAprobado['fecha_rechazo']);

        $itemRechazado = $datos->firstWhere('id', $prestamoRechazado->id);
        $this->assertSame($admin->id, $itemRechazado['gestionado_por']);
        $this->assertSame('Admin Gestor', $itemRechazado['usuario_gestion']['name']);
        $this->assertNotNull($itemRechazado['fecha_rechazo']);
        $this->assertSame('Equipo no disponible en el horario requerido.', $itemRechazado['motivo_rechazo']);

        // Verificación en detalle (show)
        $resDetalleRechazado = $this->actingAs($admin, 'sanctum')->getJson("/api/prestamos/{$prestamoRechazado->id}");
        $resDetalleRechazado->assertStatus(200)
            ->assertJsonPath('data.gestionado_por', $admin->id)
            ->assertJsonPath('data.usuario_gestion.id', $admin->id)
            ->assertJsonPath('data.usuario_gestion.name', 'Admin Gestor')
            ->assertJsonPath('data.motivo_rechazo', 'Equipo no disponible en el horario requerido.');
        $this->assertNotNull($resDetalleRechazado->json('data.fecha_rechazo'));
    }

    public function test_solicitante_puede_ver_motivo_de_rechazo_de_su_solicitud(): void
    {
        $solicitante = User::factory()->create();
        $solicitante->assignRole('usuario');

        $encargado = User::factory()->create();
        $encargado->assignRole('encargado');

        $prestamo = Prestamo::factory()->rechazado()->create([
            'usuario_id' => $solicitante->id,
            'gestionado_por' => $encargado->id,
            'motivo_rechazo' => 'Mantenimiento preventivo programado.',
        ]);

        $response = $this->actingAs($solicitante, 'sanctum')
            ->getJson("/api/prestamos/{$prestamo->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.motivo_rechazo', 'Mantenimiento preventivo programado.');
    }
}

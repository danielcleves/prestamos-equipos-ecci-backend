<?php

namespace Tests\Feature\Api;

use App\Enums\CondicionEquipo;
use App\Enums\EstadoPrestamo;
use App\Models\Equipo;
use App\Models\Prestamo;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AprobacionPrestamoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_personal_encargado_puede_aprobar_solicitud_correctamente(): void
    {
        $encargado = User::factory()->create();
        $encargado->assignRole('encargado');

        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_DISPONIBLE]);
        $prestamo = Prestamo::factory()->solicitado()->create([
            'equipo_id' => $equipo->id,
        ]);

        $response = $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/aprobacion");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $prestamo->id)
            ->assertJsonPath('data.estado', EstadoPrestamo::Aprobado->value)
            ->assertJsonPath('data.estado_etiqueta', 'Aprobado');

        $prestamoActualizado = Prestamo::findOrFail($prestamo->id);
        $this->assertSame(EstadoPrestamo::Aprobado, $prestamoActualizado->estado);
        $this->assertSame($encargado->id, $prestamoActualizado->gestionado_por);
        $this->assertNotNull($prestamoActualizado->fecha_aprobacion);

        // La aprobación no altera el estado del equipo físico
        $this->assertSame(Equipo::ESTADO_DISPONIBLE, $equipo->fresh()->estado);
    }

    public function test_admin_puede_aprobar_solicitud_correctamente(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_DISPONIBLE]);
        $prestamo = Prestamo::factory()->solicitado()->create([
            'equipo_id' => $equipo->id,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/aprobacion");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $prestamo->id)
            ->assertJsonPath('data.estado', EstadoPrestamo::Aprobado->value);

        $prestamoActualizado = Prestamo::findOrFail($prestamo->id);
        $this->assertSame(EstadoPrestamo::Aprobado, $prestamoActualizado->estado);
        $this->assertSame($admin->id, $prestamoActualizado->gestionado_por);
    }

    public function test_rol_usuario_recibe_403_al_intentar_aprobar(): void
    {
        $usuario = User::factory()->create();
        $usuario->assignRole('usuario');

        $prestamo = Prestamo::factory()->solicitado()->create();

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/aprobacion");

        $response->assertStatus(403)
            ->assertJsonPath('message', 'No tienes permiso para realizar esta acción.');
    }

    public function test_sin_token_recibe_401(): void
    {
        $prestamo = Prestamo::factory()->solicitado()->create();

        $this->postJson("/api/prestamos/{$prestamo->id}/aprobacion")
            ->assertStatus(401)
            ->assertJsonPath('message', 'No autenticado.');
    }

    public function test_rechaza_aprobacion_si_prestamo_esta_en_estado_distinto_de_solicitado(): void
    {
        $encargado = User::factory()->create();
        $encargado->assignRole('encargado');

        // Ya aprobado
        $prestamoAprobado = Prestamo::factory()->aprobado()->create();
        $response = $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamoAprobado->id}/aprobacion");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['estado']);
        $this->assertSame(
            'El préstamo está en estado Aprobado y no puede pasar a Aprobado.',
            $response->json('errors.estado.0')
        );

        // Ya rechazado
        $prestamoRechazado = Prestamo::factory()->rechazado()->create();
        $response = $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamoRechazado->id}/aprobacion");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['estado']);
        $this->assertSame(
            'El préstamo está en estado Rechazado y no puede pasar a Aprobado.',
            $response->json('errors.estado.0')
        );

        // Entregado
        $prestamoEntregado = Prestamo::factory()->entregado()->create();
        $response = $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamoEntregado->id}/aprobacion");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['estado']);
        $this->assertSame(
            'El préstamo está en estado Entregado y no puede pasar a Aprobado.',
            $response->json('errors.estado.0')
        );

        // Devuelto
        $prestamoDevuelto = Prestamo::factory()->devuelto()->create();
        $response = $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamoDevuelto->id}/aprobacion");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['estado']);
        $this->assertSame(
            'El préstamo está en estado Devuelto y no puede pasar a Aprobado.',
            $response->json('errors.estado.0')
        );
    }

    public function test_rechaza_aprobacion_si_equipo_esta_en_mantenimiento_en_prestamo_o_dado_de_baja(): void
    {
        $encargado = User::factory()->create();
        $encargado->assignRole('encargado');

        // Equipo en mantenimiento
        $equipoMantenimiento = Equipo::factory()->create(['estado' => Equipo::ESTADO_MANTENIMIENTO]);
        $prestamoMantenimiento = Prestamo::factory()->solicitado()->create(['equipo_id' => $equipoMantenimiento->id]);

        $response = $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamoMantenimiento->id}/aprobacion");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['equipo']);
        $this->assertSame(
            'El equipo seleccionado se encuentra en mantenimiento y no está disponible para préstamo.',
            $response->json('errors.equipo.0')
        );

        // Equipo en préstamo
        $equipoEnPrestamo = Equipo::factory()->create(['estado' => Equipo::ESTADO_EN_PRESTAMO]);
        $prestamoEnPrestamo = Prestamo::factory()->solicitado()->create(['equipo_id' => $equipoEnPrestamo->id]);

        $response = $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamoEnPrestamo->id}/aprobacion");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['equipo']);
        $this->assertSame(
            'El equipo seleccionado ya se encuentra en préstamo.',
            $response->json('errors.equipo.0')
        );

        // Equipo dado de baja
        $equipoDadoBaja = Equipo::factory()->create(['estado' => Equipo::ESTADO_DADO_DE_BAJA]);
        $prestamoDadoBaja = Prestamo::factory()->solicitado()->create(['equipo_id' => $equipoDadoBaja->id]);

        $response = $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamoDadoBaja->id}/aprobacion");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['equipo']);
        $this->assertSame(
            'El equipo seleccionado ha sido dado de baja y no puede ser prestado.',
            $response->json('errors.equipo.0')
        );
    }

    public function test_valida_estado_contra_datos_frescos_en_base_de_datos_al_aprobar(): void
    {
        $encargado = User::factory()->create();
        $encargado->assignRole('encargado');

        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_DISPONIBLE]);
        $prestamo = Prestamo::factory()->solicitado()->create(['equipo_id' => $equipo->id]);

        // Simula que concurrentemente otra petición cambió el estado a 'cancelado' en la base
        DB::table('prestamos')->where('id', $prestamo->id)->update([
            'estado' => EstadoPrestamo::Cancelado->value,
        ]);

        $response = $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/aprobacion");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['estado']);
        $this->assertSame(
            'El préstamo está en estado Cancelado y no puede pasar a Aprobado.',
            $response->json('errors.estado.0')
        );
    }

    public function test_despues_de_aprobar_el_flujo_de_entrega_funciona_sin_tinker(): void
    {
        $encargado = User::factory()->create();
        $encargado->assignRole('encargado');

        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_DISPONIBLE]);
        $prestamo = Prestamo::factory()->solicitado()->create(['equipo_id' => $equipo->id]);

        // 1. Paso de aprobación vía API
        $responseAprobacion = $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/aprobacion");

        $responseAprobacion->assertStatus(200)
            ->assertJsonPath('data.estado', EstadoPrestamo::Aprobado->value);

        // 2. Paso de entrega vía API directamente sobre el préstamo recién aprobado
        $responseEntrega = $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/entrega", [
                'condicion_entrega' => CondicionEquipo::Bueno->value,
                'observaciones' => 'Entrega posterior a aprobación vía API',
            ]);

        $responseEntrega->assertStatus(200)
            ->assertJsonPath('data.estado', EstadoPrestamo::Entregado->value)
            ->assertJsonPath('data.observaciones_entrega', 'Entrega posterior a aprobación vía API');

        $this->assertSame(Equipo::ESTADO_EN_PRESTAMO, $equipo->fresh()->estado);
    }
}

<?php

namespace Tests\Feature\Api;

use App\Enums\CondicionEquipo;
use App\Enums\EstadoPrestamo;
use App\Models\Equipo;
use App\Models\HistorialEstado;
use App\Models\Prestamo;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntregaPrestamoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_personal_encargado_puede_registrar_entrega_correctamente(): void
    {
        $encargado = User::factory()->create();
        $encargado->assignRole('encargado');

        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_DISPONIBLE]);
        $prestamo = Prestamo::factory()->aprobado()->create([
            'equipo_id' => $equipo->id,
            'observaciones' => null,
        ]);

        $historialPrevioCount = HistorialEstado::where('equipo_id', $equipo->id)->count();

        $response = $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/entrega", [
                'condicion_entrega' => CondicionEquipo::Bueno->value,
                'observaciones' => 'Equipo entregado con maletín y cables',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $prestamo->id)
            ->assertJsonPath('data.estado', EstadoPrestamo::Entregado->value)
            ->assertJsonPath('data.estado_etiqueta', 'Entregado')
            ->assertJsonPath('data.entregado_por', $encargado->id)
            ->assertJsonPath('data.condicion_entrega', CondicionEquipo::Bueno->value)
            ->assertJsonPath('data.observaciones', 'Entrega: Equipo entregado con maletín y cables');

        // El préstamo debe estar en estado entregado
        $prestamoActualizado = Prestamo::findOrFail($prestamo->id);
        $this->assertSame(EstadoPrestamo::Entregado, $prestamoActualizado->estado);
        $this->assertSame($encargado->id, $prestamoActualizado->entregado_por);
        $this->assertNotNull($prestamoActualizado->fecha_entrega_real);

        // El equipo debe pasar a en_prestamo
        $this->assertSame(Equipo::ESTADO_EN_PRESTAMO, $equipo->fresh()->estado);

        // Una sola fila nueva en historial_estados (disponible -> en_prestamo) y ninguna duplicada
        $nuevoHistorial = HistorialEstado::where('equipo_id', $equipo->id)->latest('id')->first();
        $this->assertSame($historialPrevioCount + 1, HistorialEstado::where('equipo_id', $equipo->id)->count());
        $this->assertSame(Equipo::ESTADO_DISPONIBLE, $nuevoHistorial->estado_anterior);
        $this->assertSame(Equipo::ESTADO_EN_PRESTAMO, $nuevoHistorial->estado_nuevo);
        $this->assertSame($encargado->id, $nuevoHistorial->user_id);
    }

    public function test_admin_puede_registrar_entrega_con_valores_por_defecto(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_DISPONIBLE]);
        $prestamo = Prestamo::factory()->aprobado()->create(['equipo_id' => $equipo->id]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/entrega", []);

        $response->assertStatus(200)
            ->assertJsonPath('data.estado', EstadoPrestamo::Entregado->value)
            ->assertJsonPath('data.condicion_entrega', CondicionEquipo::Bueno->value);

        $prestamoActualizado = Prestamo::findOrFail($prestamo->id);
        $this->assertSame(EstadoPrestamo::Entregado, $prestamoActualizado->estado);
        $this->assertSame(CondicionEquipo::Bueno, $prestamoActualizado->condicion_entrega);
        $this->assertNotNull($prestamoActualizado->fecha_entrega_real);
    }

    public function test_rol_usuario_recibe_403_al_intentar_registrar_entrega(): void
    {
        $usuario = User::factory()->create();
        $usuario->assignRole('usuario');

        $prestamo = Prestamo::factory()->aprobado()->create();

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/entrega", []);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'No tienes permiso para realizar esta acción.');
    }

    public function test_rechaza_entrega_si_el_prestamo_no_esta_en_estado_aprobado(): void
    {
        $encargado = User::factory()->create();
        $encargado->assignRole('encargado');

        // Préstamo en estado solicitado (no aprobado aún)
        $prestamoSolicitado = Prestamo::factory()->solicitado()->create();

        $response = $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamoSolicitado->id}/entrega", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['estado']);

        $this->assertSame(
            'El préstamo está en estado Solicitado y no puede pasar a Entregado.',
            $response->json('errors.estado.0')
        );

        // Préstamo ya entregado
        $prestamoEntregado = Prestamo::factory()->entregado()->create();

        $responseEntregado = $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamoEntregado->id}/entrega", []);

        $responseEntregado->assertStatus(422)
            ->assertJsonValidationErrors(['estado']);

        $this->assertSame(
            'El préstamo está en estado Entregado y no puede pasar a Entregado.',
            $responseEntregado->json('errors.estado.0')
        );
    }

    public function test_rechaza_entrega_si_el_equipo_no_esta_disponible(): void
    {
        $encargado = User::factory()->create();
        $encargado->assignRole('encargado');

        $equipoEnMantenimiento = Equipo::factory()->create(['estado' => Equipo::ESTADO_MANTENIMIENTO]);
        $prestamo = Prestamo::factory()->aprobado()->create(['equipo_id' => $equipoEnMantenimiento->id]);

        $response = $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/entrega", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['equipo']);

        $this->assertSame(
            'El equipo no se encuentra disponible para registrar su entrega.',
            $response->json('errors.equipo.0')
        );
    }

    public function test_sin_token_recibe_401(): void
    {
        $prestamo = Prestamo::factory()->aprobado()->create();

        $this->postJson("/api/prestamos/{$prestamo->id}/entrega", [])
            ->assertStatus(401)
            ->assertJsonPath('message', 'No autenticado.');
    }
}

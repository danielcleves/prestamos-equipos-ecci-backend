<?php

namespace Tests\Feature\Api;

use App\Enums\EstadoPrestamo;
use App\Models\Equipo;
use App\Models\Prestamo;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RechazoPrestamoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_personal_encargado_puede_rechazar_solicitud_correctamente(): void
    {
        $encargado = User::factory()->create();
        $encargado->assignRole('encargado');

        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_DISPONIBLE]);
        $prestamo = Prestamo::factory()->solicitado()->create([
            'equipo_id' => $equipo->id,
        ]);

        $response = $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/rechazo", [
                'motivo' => 'Equipo reservado para evento institucional.',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $prestamo->id)
            ->assertJsonPath('data.estado', EstadoPrestamo::Rechazado->value)
            ->assertJsonPath('data.estado_etiqueta', 'Rechazado');

        $prestamoActualizado = Prestamo::findOrFail($prestamo->id);
        $this->assertSame(EstadoPrestamo::Rechazado, $prestamoActualizado->estado);
        $this->assertSame($encargado->id, $prestamoActualizado->gestionado_por);
        $this->assertSame('Equipo reservado para evento institucional.', $prestamoActualizado->motivo_rechazo);
        $this->assertNotNull($prestamoActualizado->fecha_rechazo);

        // El equipo no se modifica
        $this->assertSame(Equipo::ESTADO_DISPONIBLE, $equipo->fresh()->estado);
    }

    public function test_admin_puede_rechazar_solicitud_correctamente(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $prestamo = Prestamo::factory()->solicitado()->create();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/rechazo", [
                'motivo' => 'Rechazado por administración general.',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $prestamo->id)
            ->assertJsonPath('data.estado', EstadoPrestamo::Rechazado->value);

        $prestamoActualizado = Prestamo::findOrFail($prestamo->id);
        $this->assertSame(EstadoPrestamo::Rechazado, $prestamoActualizado->estado);
        $this->assertSame($admin->id, $prestamoActualizado->gestionado_por);
        $this->assertSame('Rechazado por administración general.', $prestamoActualizado->motivo_rechazo);
    }

    public function test_rechazo_exige_motivo_no_vacio_y_maximo_1000_caracteres(): void
    {
        $encargado = User::factory()->create();
        $encargado->assignRole('encargado');

        $prestamo = Prestamo::factory()->solicitado()->create();

        // Sin motivo
        $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/rechazo", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['motivo'])
            ->assertJsonPath('errors.motivo.0', 'El motivo del rechazo es obligatorio.');

        // Solo espacios
        $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/rechazo", ['motivo' => "   \t\n  "])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['motivo']);

        // Más de 1000 caracteres
        $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/rechazo", ['motivo' => str_repeat('a', 1001)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['motivo'])
            ->assertJsonPath('errors.motivo.0', 'El motivo del rechazo no puede superar los 1000 caracteres.');
    }

    public function test_rechazo_almacena_motivo_recortado(): void
    {
        $encargado = User::factory()->create();
        $encargado->assignRole('encargado');

        $prestamo = Prestamo::factory()->solicitado()->create();

        $response = $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/rechazo", [
                'motivo' => "   Motivo con espacios en los extremos   \n",
            ]);

        $response->assertStatus(200);

        $this->assertSame('Motivo con espacios en los extremos', Prestamo::findOrFail($prestamo->id)->motivo_rechazo);
    }

    public function test_rol_usuario_recibe_403_al_intentar_rechazar(): void
    {
        $usuario = User::factory()->create();
        $usuario->assignRole('usuario');

        $prestamo = Prestamo::factory()->solicitado()->create();

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/rechazo", [
                'motivo' => 'Intento de rechazo por usuario',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'No tienes permiso para realizar esta acción.');
    }

    public function test_sin_token_recibe_401(): void
    {
        $prestamo = Prestamo::factory()->solicitado()->create();

        $this->postJson("/api/prestamos/{$prestamo->id}/rechazo", [
            'motivo' => 'Intento sin autenticar',
        ])
            ->assertStatus(401)
            ->assertJsonPath('message', 'No autenticado.');
    }

    public function test_rechaza_solicitud_si_prestamo_esta_en_estado_distinto_de_solicitado(): void
    {
        $encargado = User::factory()->create();
        $encargado->assignRole('encargado');

        // Ya aprobado
        $prestamoAprobado = Prestamo::factory()->aprobado()->create();
        $response = $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamoAprobado->id}/rechazo", ['motivo' => 'Motivo']);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['estado']);
        $this->assertSame(
            'El préstamo está en estado Aprobado y no puede pasar a Rechazado.',
            $response->json('errors.estado.0')
        );

        // Ya rechazado
        $prestamoRechazado = Prestamo::factory()->rechazado()->create();
        $response = $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamoRechazado->id}/rechazo", ['motivo' => 'Motivo']);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['estado']);
        $this->assertSame(
            'El préstamo está en estado Rechazado y no puede pasar a Rechazado.',
            $response->json('errors.estado.0')
        );

        // Ya entregado
        $prestamoEntregado = Prestamo::factory()->entregado()->create();
        $response = $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamoEntregado->id}/rechazo", ['motivo' => 'Motivo']);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['estado']);
        $this->assertSame(
            'El préstamo está en estado Entregado y no puede pasar a Rechazado.',
            $response->json('errors.estado.0')
        );

        // Ya devuelto
        $prestamoDevuelto = Prestamo::factory()->devuelto()->create();
        $response = $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamoDevuelto->id}/rechazo", ['motivo' => 'Motivo']);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['estado']);
        $this->assertSame(
            'El préstamo está en estado Devuelto y no puede pasar a Rechazado.',
            $response->json('errors.estado.0')
        );
    }

    public function test_un_prestamo_rechazado_no_se_puede_aprobar_entregar_ni_devolver(): void
    {
        $encargado = User::factory()->create();
        $encargado->assignRole('encargado');

        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_DISPONIBLE]);
        $prestamo = Prestamo::factory()->rechazado()->create([
            'equipo_id' => $equipo->id,
        ]);

        // Intentar aprobar
        $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/aprobacion")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['estado']);

        // Intentar entregar
        $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/entrega", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['estado']);

        // Intentar devolver (colocando el equipo en préstamo para evaluar la restricción por estado del préstamo)
        $equipo->update(['estado' => Equipo::ESTADO_EN_PRESTAMO]);
        $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/devolucion", [
                'condicion_devolucion' => 'bueno',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['estado']);
    }

    public function test_tras_rechazo_el_mismo_usuario_puede_volver_a_solicitar_ese_equipo_en_las_mismas_fechas(): void
    {
        $usuario = User::factory()->create();
        $usuario->assignRole('usuario');

        $encargado = User::factory()->create();
        $encargado->assignRole('encargado');

        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_DISPONIBLE]);

        $fechaInicio = now()->addDay()->setHour(9)->setMinute(0)->setSecond(0);
        $fechaDevolucion = (clone $fechaInicio)->addDays(2)->setHour(17)->setMinute(0)->setSecond(0);

        // 1. Crear primera solicitud
        $response1 = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/prestamos', [
                'equipo_id' => $equipo->id,
                'motivo' => 'Primera solicitud',
                'fecha_inicio' => $fechaInicio->format('Y-m-d H:i:s'),
                'fecha_devolucion_estimada' => $fechaDevolucion->format('Y-m-d H:i:s'),
            ]);
        $response1->assertStatus(201);
        $prestamoId = $response1->json('data.id');

        // 2. Rechazar la primera solicitud
        $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamoId}/rechazo", [
                'motivo' => 'Rechazado temporalmente por revisión',
            ])
            ->assertStatus(200);

        // 3. El mismo usuario vuelve a solicitar exactamente el mismo equipo en las mismas fechas
        $response2 = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/prestamos', [
                'equipo_id' => $equipo->id,
                'motivo' => 'Segunda solicitud tras aclaración',
                'fecha_inicio' => $fechaInicio->format('Y-m-d H:i:s'),
                'fecha_devolucion_estimada' => $fechaDevolucion->format('Y-m-d H:i:s'),
            ]);

        $response2->assertStatus(201)
            ->assertJsonPath('data.estado', EstadoPrestamo::Solicitado->value);
    }

    public function test_un_prestamo_rechazado_no_cuenta_para_el_limite_de_prestamos_activos_del_usuario(): void
    {
        $usuario = User::factory()->create();
        $usuario->assignRole('usuario');

        $encargado = User::factory()->create();
        $encargado->assignRole('encargado');

        config(['prestamos.max_activos_por_usuario' => 2]);

        $equipo1 = Equipo::factory()->create(['estado' => Equipo::ESTADO_DISPONIBLE]);
        $equipo2 = Equipo::factory()->create(['estado' => Equipo::ESTADO_DISPONIBLE]);
        $equipo3 = Equipo::factory()->create(['estado' => Equipo::ESTADO_DISPONIBLE]);

        // Crear 1 préstamo activo (solicitado)
        Prestamo::factory()->solicitado()->create([
            'usuario_id' => $usuario->id,
            'equipo_id' => $equipo1->id,
        ]);

        // Crear una solicitud que se rechaza
        $prestamoARechazar = Prestamo::factory()->solicitado()->create([
            'usuario_id' => $usuario->id,
            'equipo_id' => $equipo2->id,
        ]);

        $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamoARechazar->id}/rechazo", [
                'motivo' => 'Rechazo que libera cupo activo',
            ])
            ->assertStatus(200);

        // Como el límite es 2 y solo tiene 1 activo (el otro fue rechazado), debe poder solicitar otro equipo
        $fechaInicio = now()->addDay()->setHour(9)->setMinute(0)->setSecond(0);
        $fechaDevolucion = (clone $fechaInicio)->addDays(2)->setHour(17)->setMinute(0)->setSecond(0);

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/prestamos', [
                'equipo_id' => $equipo3->id,
                'motivo' => 'Tercera solicitud que aprovecha el cupo libre',
                'fecha_inicio' => $fechaInicio->format('Y-m-d H:i:s'),
                'fecha_devolucion_estimada' => $fechaDevolucion->format('Y-m-d H:i:s'),
            ]);

        $response->assertStatus(201);
    }
}

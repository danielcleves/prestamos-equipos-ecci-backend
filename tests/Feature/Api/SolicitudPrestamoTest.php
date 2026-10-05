<?php

namespace Tests\Feature\Api;

use App\Enums\EstadoPrestamo;
use App\Models\Equipo;
use App\Models\Prestamo;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SolicitudPrestamoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_usuario_autenticado_puede_solicitar_un_equipo_disponible(): void
    {
        $usuario = User::factory()->create();
        $usuario->assignRole('usuario');

        $equipo = Equipo::factory()->create([
            'estado' => Equipo::ESTADO_DISPONIBLE,
        ]);

        $fechaInicio = now()->addDay()->setHour(10)->setMinute(0)->setSecond(0);
        $fechaDevolucion = (clone $fechaInicio)->addDays(3);

        $response = $this->actingAs($usuario, 'sanctum')->postJson('/api/prestamos', [
            'equipo_id' => $equipo->id,
            'motivo' => 'Práctica de laboratorio de redes',
            'fecha_inicio' => $fechaInicio->toDateTimeString(),
            'fecha_devolucion_estimada' => $fechaDevolucion->toDateTimeString(),
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.usuario_id', $usuario->id)
            ->assertJsonPath('data.equipo_id', $equipo->id)
            ->assertJsonPath('data.estado', EstadoPrestamo::Solicitado->value)
            ->assertJsonPath('data.estado_etiqueta', 'Solicitado')
            ->assertJsonPath('data.motivo', 'Práctica de laboratorio de redes');

        $this->assertDatabaseHas('prestamos', [
            'usuario_id' => $usuario->id,
            'equipo_id' => $equipo->id,
            'estado' => EstadoPrestamo::Solicitado->value,
            'motivo' => 'Práctica de laboratorio de redes',
        ]);

        // La solicitud NO debe cambiar el estado del equipo
        $this->assertSame(Equipo::ESTADO_DISPONIBLE, $equipo->fresh()->estado);
    }

    public function test_falla_sin_motivo_con_motivo_vacio_o_solo_espacios(): void
    {
        $usuario = User::factory()->create();
        $usuario->assignRole('usuario');
        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_DISPONIBLE]);

        $fechaInicio = now()->addDay()->toDateTimeString();
        $fechaDevolucion = now()->addDays(2)->toDateTimeString();

        // Sin motivo
        $this->actingAs($usuario, 'sanctum')->postJson('/api/prestamos', [
            'equipo_id' => $equipo->id,
            'fecha_inicio' => $fechaInicio,
            'fecha_devolucion_estimada' => $fechaDevolucion,
        ])->assertStatus(422)->assertJsonValidationErrors(['motivo']);

        // Motivo solo con espacios
        $this->actingAs($usuario, 'sanctum')->postJson('/api/prestamos', [
            'equipo_id' => $equipo->id,
            'motivo' => '    ',
            'fecha_inicio' => $fechaInicio,
            'fecha_devolucion_estimada' => $fechaDevolucion,
        ])->assertStatus(422)->assertJsonValidationErrors(['motivo']);

        // Motivo de más de 1000 caracteres
        $this->actingAs($usuario, 'sanctum')->postJson('/api/prestamos', [
            'equipo_id' => $equipo->id,
            'motivo' => str_repeat('a', 1001),
            'fecha_inicio' => $fechaInicio,
            'fecha_devolucion_estimada' => $fechaDevolucion,
        ])->assertStatus(422)->assertJsonValidationErrors(['motivo']);
    }

    public function test_rechaza_solicitud_de_equipo_dado_de_baja_con_mensaje_especifico(): void
    {
        $usuario = User::factory()->create();
        $usuario->assignRole('usuario');
        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_DADO_DE_BAJA]);

        $response = $this->actingAs($usuario, 'sanctum')->postJson('/api/prestamos', [
            'equipo_id' => $equipo->id,
            'motivo' => 'Uso en clase',
            'fecha_inicio' => now()->addDay()->toDateTimeString(),
            'fecha_devolucion_estimada' => now()->addDays(2)->toDateTimeString(),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['equipo_id']);

        $this->assertSame(
            'El equipo seleccionado ha sido dado de baja y no puede solicitarse.',
            $response->json('errors.equipo_id.0')
        );
    }

    public function test_rechaza_solicitud_de_equipo_en_mantenimiento_con_mensaje_especifico(): void
    {
        $usuario = User::factory()->create();
        $usuario->assignRole('usuario');
        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_MANTENIMIENTO]);

        $response = $this->actingAs($usuario, 'sanctum')->postJson('/api/prestamos', [
            'equipo_id' => $equipo->id,
            'motivo' => 'Uso en clase',
            'fecha_inicio' => now()->addDay()->toDateTimeString(),
            'fecha_devolucion_estimada' => now()->addDays(2)->toDateTimeString(),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['equipo_id']);

        $this->assertSame(
            'El equipo seleccionado se encuentra en mantenimiento y no está disponible para préstamo.',
            $response->json('errors.equipo_id.0')
        );
    }

    public function test_rechaza_solicitud_de_equipo_en_prestamo(): void
    {
        $usuario = User::factory()->create();
        $usuario->assignRole('usuario');
        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_EN_PRESTAMO]);

        $response = $this->actingAs($usuario, 'sanctum')->postJson('/api/prestamos', [
            'equipo_id' => $equipo->id,
            'motivo' => 'Uso en clase',
            'fecha_inicio' => now()->addDay()->toDateTimeString(),
            'fecha_devolucion_estimada' => now()->addDays(2)->toDateTimeString(),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['equipo_id']);

        $this->assertSame(
            'El equipo seleccionado no se encuentra disponible para préstamo.',
            $response->json('errors.equipo_id.0')
        );
    }

    public function test_rechaza_fechas_invalidas_o_en_el_pasado(): void
    {
        $usuario = User::factory()->create();
        $usuario->assignRole('usuario');
        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_DISPONIBLE]);

        // fecha_inicio en el pasado
        $this->actingAs($usuario, 'sanctum')->postJson('/api/prestamos', [
            'equipo_id' => $equipo->id,
            'motivo' => 'Uso en clase',
            'fecha_inicio' => now()->subDays(2)->toDateTimeString(),
            'fecha_devolucion_estimada' => now()->addDay()->toDateTimeString(),
        ])->assertStatus(422)->assertJsonValidationErrors(['fecha_inicio']);

        // fecha_devolucion_estimada anterior a fecha_inicio
        $this->actingAs($usuario, 'sanctum')->postJson('/api/prestamos', [
            'equipo_id' => $equipo->id,
            'motivo' => 'Uso en clase',
            'fecha_inicio' => now()->addDays(3)->toDateTimeString(),
            'fecha_devolucion_estimada' => now()->addDay()->toDateTimeString(),
        ])->assertStatus(422)->assertJsonValidationErrors(['fecha_devolucion_estimada']);
    }

    public function test_rechaza_solicitud_que_supera_duracion_maxima_dias(): void
    {
        $usuario = User::factory()->create();
        $usuario->assignRole('usuario');
        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_DISPONIBLE]);

        $fechaInicio = now()->addDay();
        // 8 días supera el límite de 7 días configurado
        $fechaDevolucion = (clone $fechaInicio)->addDays(8);

        $response = $this->actingAs($usuario, 'sanctum')->postJson('/api/prestamos', [
            'equipo_id' => $equipo->id,
            'motivo' => 'Semestre de tesis',
            'fecha_inicio' => $fechaInicio->toDateTimeString(),
            'fecha_devolucion_estimada' => $fechaDevolucion->toDateTimeString(),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['fecha_devolucion_estimada']);

        $this->assertStringContainsString(
            'no puede superar los 7 días posteriores',
            $response->json('errors.fecha_devolucion_estimada.0')
        );
    }

    public function test_rechaza_solicitud_cuando_se_cruzan_las_fechas_con_otro_prestamo(): void
    {
        $usuario1 = User::factory()->create();
        $usuario1->assignRole('usuario');
        $usuario2 = User::factory()->create();
        $usuario2->assignRole('usuario');

        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_DISPONIBLE]);

        // Préstamo existente: del día 2 al día 5
        Prestamo::factory()->create([
            'equipo_id' => $equipo->id,
            'estado' => EstadoPrestamo::Solicitado,
            'fecha_inicio' => now()->addDays(2),
            'fecha_devolucion_estimada' => now()->addDays(5),
        ]);

        // Intento de cruce: del día 3 al día 6
        $response = $this->actingAs($usuario2, 'sanctum')->postJson('/api/prestamos', [
            'equipo_id' => $equipo->id,
            'motivo' => 'Proyecto final',
            'fecha_inicio' => now()->addDays(3)->toDateTimeString(),
            'fecha_devolucion_estimada' => now()->addDays(6)->toDateTimeString(),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['equipo_id']);

        $this->assertSame(
            'El equipo ya cuenta con un préstamo solicitado o activo durante el período seleccionado.',
            $response->json('errors.equipo_id.0')
        );
    }

    public function test_rechaza_solicitud_cuando_el_usuario_supera_limite_de_activos(): void
    {
        $usuario = User::factory()->create();
        $usuario->assignRole('usuario');

        // Crear 3 préstamos activos para este usuario (máximo permitido)
        Prestamo::factory()->count(3)->create([
            'usuario_id' => $usuario->id,
            'estado' => EstadoPrestamo::Solicitado,
        ]);

        $nuevoEquipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_DISPONIBLE]);

        $response = $this->actingAs($usuario, 'sanctum')->postJson('/api/prestamos', [
            'equipo_id' => $nuevoEquipo->id,
            'motivo' => 'Un cuarto equipo',
            'fecha_inicio' => now()->addDay()->toDateTimeString(),
            'fecha_devolucion_estimada' => now()->addDays(2)->toDateTimeString(),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['usuario_id']);

        $this->assertSame(
            'Has alcanzado el límite máximo de 3 préstamos activos.',
            $response->json('errors.usuario_id.0')
        );
    }

    public function test_el_cliente_no_puede_fijar_fecha_solicitud_usuario_id_ni_estado(): void
    {
        $usuarioAutenticado = User::factory()->create();
        $usuarioAutenticado->assignRole('usuario');

        $otroUsuario = User::factory()->create();
        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_DISPONIBLE]);

        $response = $this->actingAs($usuarioAutenticado, 'sanctum')->postJson('/api/prestamos', [
            'equipo_id' => $equipo->id,
            'motivo' => 'Motivo legítimo',
            'fecha_inicio' => now()->addDay()->toDateTimeString(),
            'fecha_devolucion_estimada' => now()->addDays(2)->toDateTimeString(),
            // Intento de suplantación y forzado de estado:
            'usuario_id' => $otroUsuario->id,
            'estado' => EstadoPrestamo::Aprobado->value,
            'fecha_solicitud' => '2000-01-01 00:00:00',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.usuario_id', $usuarioAutenticado->id)
            ->assertJsonPath('data.estado', EstadoPrestamo::Solicitado->value);

        $prestamo = Prestamo::findOrFail($response->json('data.id'));
        $this->assertSame($usuarioAutenticado->id, $prestamo->usuario_id);
        $this->assertSame(EstadoPrestamo::Solicitado, $prestamo->estado);
        $this->assertNotSame('2000-01-01 00:00:00', $prestamo->fecha_solicitud->toDateTimeString());
    }

    public function test_visibilidad_de_listado_por_rol(): void
    {
        $usuario1 = User::factory()->create();
        $usuario1->assignRole('usuario');
        $usuario2 = User::factory()->create();
        $usuario2->assignRole('usuario');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $encargado = User::factory()->create();
        $encargado->assignRole('encargado');

        Prestamo::factory()->count(2)->create(['usuario_id' => $usuario1->id]);
        Prestamo::factory()->count(3)->create(['usuario_id' => $usuario2->id]);

        // Usuario 1 solo ve los 2 suyos
        $resUsuario = $this->actingAs($usuario1, 'sanctum')->getJson('/api/prestamos');
        $resUsuario->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);

        // Admin ve los 5 préstamos
        $resAdmin = $this->actingAs($admin, 'sanctum')->getJson('/api/prestamos');
        $resAdmin->assertStatus(200)
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.total', 5);

        // Encargado ve los 5 préstamos
        $resEncargado = $this->actingAs($encargado, 'sanctum')->getJson('/api/prestamos');
        $resEncargado->assertStatus(200)
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.total', 5);
    }

    public function test_visibilidad_de_prestamo_individual_segun_politica(): void
    {
        $usuarioDueno = User::factory()->create();
        $usuarioDueno->assignRole('usuario');
        $usuarioExtrano = User::factory()->create();
        $usuarioExtrano->assignRole('usuario');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $prestamo = Prestamo::factory()->create(['usuario_id' => $usuarioDueno->id]);

        // El dueño puede ver su préstamo
        $this->actingAs($usuarioDueno, 'sanctum')
            ->getJson("/api/prestamos/{$prestamo->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $prestamo->id);

        // Un usuario ajeno recibe 403
        $this->actingAs($usuarioExtrano, 'sanctum')
            ->getJson("/api/prestamos/{$prestamo->id}")
            ->assertStatus(403)
            ->assertJsonPath('message', 'No tienes permiso para realizar esta acción.');

        // El admin puede ver el préstamo de cualquier usuario
        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/prestamos/{$prestamo->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $prestamo->id);
    }

    public function test_requiere_autenticacion_sin_token_retorna_401(): void
    {
        $this->postJson('/api/prestamos', [])
            ->assertStatus(401)
            ->assertJsonPath('message', 'No autenticado.');

        $this->getJson('/api/prestamos')
            ->assertStatus(401)
            ->assertJsonPath('message', 'No autenticado.');
    }
}

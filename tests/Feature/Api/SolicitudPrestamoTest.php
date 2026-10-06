<?php

namespace Tests\Feature\Api;

use App\Enums\EstadoPrestamo;
use App\Http\Resources\PrestamoResource;
use App\Models\Equipo;
use App\Models\Prestamo;
use App\Models\User;
use Carbon\Carbon;
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

    public function test_valida_limite_de_activos_con_datos_frescos_de_la_base_de_datos(): void
    {
        $usuario = User::factory()->create();
        $usuario->assignRole('usuario');
        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_DISPONIBLE]);

        // La instancia $usuario en memoria se creó antes.
        // Simulamos que tras cargar la instancia se insertaron préstamos activos directamente en la base de datos:
        Prestamo::factory()->count(3)->create([
            'usuario_id' => $usuario->id,
            'estado' => EstadoPrestamo::Solicitado,
        ]);

        $service = app(\App\Services\SolicitudPrestamoService::class);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        try {
            $service->solicitar($usuario, [
                'equipo_id' => $equipo->id,
                'motivo' => 'Intento concurrente de solicitud',
                'fecha_inicio' => now()->addDay(),
                'fecha_devolucion_estimada' => now()->addDays(2),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('usuario_id', $e->errors());
            $this->assertSame(
                'Has alcanzado el límite máximo de 3 préstamos activos.',
                $e->errors()['usuario_id'][0]
            );
            throw $e;
        }
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

    public function test_interpreta_fechas_sin_desplazamiento_en_hora_de_bogota_y_almacena_en_utc(): void
    {
        $usuario = User::factory()->create();
        $usuario->assignRole('usuario');
        $equipo = Equipo::factory()->create();

        $response = $this->actingAs($usuario, 'sanctum')->postJson('/api/prestamos', [
            'equipo_id' => $equipo->id,
            'motivo' => 'Préstamo para pruebas de zona horaria',
            'fecha_inicio' => '2026-10-15 08:00:00',
            'fecha_devolucion_estimada' => '2026-10-16 18:00:00',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.fecha_inicio', '2026-10-15T08:00:00-05:00')
            ->assertJsonPath('data.fecha_devolucion_estimada', '2026-10-16T18:00:00-05:00');

        $prestamo = Prestamo::latest('id')->firstOrFail();
        $this->assertSame('2026-10-15 13:00:00', $prestamo->fecha_inicio->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-16 23:00:00', $prestamo->fecha_devolucion_estimada->format('Y-m-d H:i:s'));
    }

    public function test_interpreta_formato_iso_sin_desplazamiento_como_hora_de_bogota(): void
    {
        $usuario = User::factory()->create();
        $usuario->assignRole('usuario');
        $equipo = Equipo::factory()->create();

        $response = $this->actingAs($usuario, 'sanctum')->postJson('/api/prestamos', [
            'equipo_id' => $equipo->id,
            'motivo' => 'Préstamo con formato Y-m-d\TH:i',
            'fecha_inicio' => '2026-10-15T08:00',
            'fecha_devolucion_estimada' => '2026-10-16T18:00',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.fecha_inicio', '2026-10-15T08:00:00-05:00')
            ->assertJsonPath('data.fecha_devolucion_estimada', '2026-10-16T18:00:00-05:00');

        $prestamo = Prestamo::latest('id')->firstOrFail();
        $this->assertSame('2026-10-15 13:00:00', $prestamo->fecha_inicio->format('Y-m-d H:i:s'));
    }

    public function test_interpreta_formato_con_desplazamiento_explicito_y_almacena_mismo_instante_utc(): void
    {
        $usuario = User::factory()->create();
        $usuario->assignRole('usuario');
        $equipo = Equipo::factory()->create();

        $response = $this->actingAs($usuario, 'sanctum')->postJson('/api/prestamos', [
            'equipo_id' => $equipo->id,
            'motivo' => 'Préstamo con offset -05:00',
            'fecha_inicio' => '2026-10-15T08:00:00-05:00',
            'fecha_devolucion_estimada' => '2026-10-16T18:00:00-05:00',
        ]);

        $response->assertStatus(201);

        $prestamo = Prestamo::latest('id')->firstOrFail();
        $this->assertSame('2026-10-15 13:00:00', $prestamo->fecha_inicio->format('Y-m-d H:i:s'));
    }

    public function test_regression_evaluacion_de_fechas_a_las_14_horas_bogota(): void
    {
        // 14:00 hora de Colombia = 19:00 UTC
        Carbon::setTestNow(Carbon::parse('2026-10-15 14:00:00', 'America/Bogota'));

        try {
            $usuario = User::factory()->create();
            $usuario->assignRole('usuario');
            $equipo = Equipo::factory()->create();

            // Caso A: las 16:00 de hoy en Colombia (faltan 2 horas) se acepta
            $resAceptada = $this->actingAs($usuario, 'sanctum')->postJson('/api/prestamos', [
                'equipo_id' => $equipo->id,
                'motivo' => 'Solicitud para más tarde hoy',
                'fecha_inicio' => '2026-10-15 16:00:00',
                'fecha_devolucion_estimada' => '2026-10-16 12:00:00',
            ]);
            $resAceptada->assertStatus(201);

            // Caso B: las 13:00 de hoy en Colombia (ya pasó hace 1 hora) se rechaza con 422
            $equipo2 = Equipo::factory()->create();
            $resRechazada = $this->actingAs($usuario, 'sanctum')->postJson('/api/prestamos', [
                'equipo_id' => $equipo2->id,
                'motivo' => 'Solicitud para hora pasada hoy',
                'fecha_inicio' => '2026-10-15 13:00:00',
                'fecha_devolucion_estimada' => '2026-10-16 12:00:00',
            ]);
            $resRechazada->assertStatus(422)
                ->assertJsonValidationErrors(['fecha_inicio']);
            $this->assertSame(
                'La fecha y hora de inicio debe ser igual o posterior al momento actual.',
                $resRechazada->json('errors.fecha_inicio.0')
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_rechaza_fecha_sin_hora_con_422(): void
    {
        $usuario = User::factory()->create();
        $usuario->assignRole('usuario');
        $equipo = Equipo::factory()->create();

        $response = $this->actingAs($usuario, 'sanctum')->postJson('/api/prestamos', [
            'equipo_id' => $equipo->id,
            'motivo' => 'Fecha sin hora',
            'fecha_inicio' => '2026-10-15',
            'fecha_devolucion_estimada' => '2026-10-16',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['fecha_inicio', 'fecha_devolucion_estimada']);
    }

    public function test_rechaza_cadenas_relativas_con_422(): void
    {
        $usuario = User::factory()->create();
        $usuario->assignRole('usuario');
        $equipo = Equipo::factory()->create();

        $response = $this->actingAs($usuario, 'sanctum')->postJson('/api/prestamos', [
            'equipo_id' => $equipo->id,
            'motivo' => 'Fecha relativa',
            'fecha_inicio' => 'tomorrow',
            'fecha_devolucion_estimada' => 'next week',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['fecha_inicio', 'fecha_devolucion_estimada']);
    }

    public function test_rechaza_formato_con_barras_con_422(): void
    {
        $usuario = User::factory()->create();
        $usuario->assignRole('usuario');
        $equipo = Equipo::factory()->create();

        $response = $this->actingAs($usuario, 'sanctum')->postJson('/api/prestamos', [
            'equipo_id' => $equipo->id,
            'motivo' => 'Fecha con barras',
            'fecha_inicio' => '15/10/2026 08:00',
            'fecha_devolucion_estimada' => '16/10/2026 18:00',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['fecha_inicio', 'fecha_devolucion_estimada']);
    }

    public function test_serializacion_con_prestamo_resource_preserva_zona_utc_en_modelo(): void
    {
        $prestamo = Prestamo::factory()->create([
            'fecha_inicio' => '2026-10-15 13:00:00',
        ]);

        $this->assertSame('UTC', $prestamo->fecha_inicio->timezoneName);

        $resource = new PrestamoResource($prestamo);
        $array = $resource->toArray(request());

        $this->assertSame('2026-10-15T08:00:00-05:00', $array['fecha_inicio']);
        // El modelo en memoria sigue intacto en UTC
        $this->assertSame('UTC', $prestamo->fecha_inicio->timezoneName);
    }

    public function test_rol_usuario_en_detalle_y_listado_no_recibe_email_ni_roles_de_personal_ni_solicitante(): void
    {
        $usuario = User::factory()->create(['name' => 'Estudiante Prueba', 'email' => 'estudiante@ecci.edu.co']);
        $usuario->assignRole('usuario');

        $personalEntrega = User::factory()->create(['name' => 'Encargado Entrega', 'email' => 'entrega@ecci.edu.co']);
        $personalEntrega->assignRole('encargado');

        $personalRecepcion = User::factory()->create(['name' => 'Encargado Recepcion', 'email' => 'recepcion@ecci.edu.co']);
        $personalRecepcion->assignRole('encargado');

        $prestamo = Prestamo::factory()->devuelto()->create([
            'usuario_id' => $usuario->id,
            'entregado_por' => $personalEntrega->id,
            'recibido_por' => $personalRecepcion->id,
        ]);

        // 1. Detalle GET /api/prestamos/{prestamo}
        $resDetalle = $this->actingAs($usuario, 'sanctum')->getJson("/api/prestamos/{$prestamo->id}");
        $resDetalle->assertStatus(200);

        // solicitante: solo id y name para rol usuario
        $resDetalle->assertJsonPath('data.solicitante.id', $usuario->id)
            ->assertJsonPath('data.solicitante.name', 'Estudiante Prueba')
            ->assertJsonMissingPath('data.solicitante.email')
            ->assertJsonMissingPath('data.solicitante.is_active')
            ->assertJsonMissingPath('data.solicitante.roles');

        // usuario_entrega y usuario_recepcion: exactamente id y name
        $this->assertSame(
            ['id' => $personalEntrega->id, 'name' => 'Encargado Entrega'],
            $resDetalle->json('data.usuario_entrega')
        );
        $this->assertSame(
            ['id' => $personalRecepcion->id, 'name' => 'Encargado Recepcion'],
            $resDetalle->json('data.usuario_recepcion')
        );

        // 2. Listado GET /api/prestamos
        $resListado = $this->actingAs($usuario, 'sanctum')->getJson('/api/prestamos');
        $resListado->assertStatus(200);

        $resListado->assertJsonPath('data.0.solicitante.id', $usuario->id)
            ->assertJsonMissingPath('data.0.solicitante.email')
            ->assertJsonMissingPath('data.0.solicitante.roles');

        $this->assertSame(
            ['id' => $personalEntrega->id, 'name' => 'Encargado Entrega'],
            $resListado->json('data.0.usuario_entrega')
        );
        $this->assertSame(
            ['id' => $personalRecepcion->id, 'name' => 'Encargado Recepcion'],
            $resListado->json('data.0.usuario_recepcion')
        );
    }

    public function test_rol_encargado_en_detalle_y_listado_recibe_email_de_solicitante_y_personal_reducido(): void
    {
        $usuario = User::factory()->create(['name' => 'Estudiante Prueba', 'email' => 'estudiante@ecci.edu.co']);
        $usuario->assignRole('usuario');

        $encargado = User::factory()->create(['name' => 'Encargado Auditor', 'email' => 'auditor@ecci.edu.co']);
        $encargado->assignRole('encargado');

        $prestamo = Prestamo::factory()->devuelto()->create([
            'usuario_id' => $usuario->id,
            'entregado_por' => $encargado->id,
            'recibido_por' => $encargado->id,
        ]);

        // 1. Detalle GET /api/prestamos/{prestamo}
        $resDetalle = $this->actingAs($encargado, 'sanctum')->getJson("/api/prestamos/{$prestamo->id}");
        $resDetalle->assertStatus(200);

        // solicitante: personal administrativo sí ve email, is_active y roles
        $resDetalle->assertJsonPath('data.solicitante.id', $usuario->id)
            ->assertJsonPath('data.solicitante.name', 'Estudiante Prueba')
            ->assertJsonPath('data.solicitante.email', 'estudiante@ecci.edu.co')
            ->assertJsonPath('data.solicitante.is_active', true)
            ->assertJsonPath('data.solicitante.roles', ['usuario']);

        // usuario_entrega y usuario_recepcion: siempre reducidos (id y name)
        $this->assertSame(
            ['id' => $encargado->id, 'name' => 'Encargado Auditor'],
            $resDetalle->json('data.usuario_entrega')
        );
        $this->assertSame(
            ['id' => $encargado->id, 'name' => 'Encargado Auditor'],
            $resDetalle->json('data.usuario_recepcion')
        );

        // 2. Listado GET /api/prestamos
        $resListado = $this->actingAs($encargado, 'sanctum')->getJson('/api/prestamos');
        $resListado->assertStatus(200);

        $resListado->assertJsonPath('data.0.solicitante.email', 'estudiante@ecci.edu.co');
        $this->assertSame(
            ['id' => $encargado->id, 'name' => 'Encargado Auditor'],
            $resListado->json('data.0.usuario_entrega')
        );
    }
}

<?php

namespace Tests\Feature\Api;

use App\Enums\CondicionEquipo;
use App\Enums\EstadoPrestamo;
use App\Models\Equipo;
use App\Models\HistorialEstado;
use App\Models\Prestamo;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DevolucionPrestamoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_devolucion_en_buen_estado_actualiza_equipo_a_disponible_y_conserva_observacion_previa(): void
    {
        $encargado = User::factory()->create();
        $encargado->assignRole('encargado');

        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_EN_PRESTAMO]);
        $prestamo = Prestamo::factory()->entregado()->create([
            'equipo_id' => $equipo->id,
            'observaciones_entrega' => 'Entregado con cargador original',
            'fecha_entrega_real' => now()->subDays(2),
        ]);

        $historialPrevioCount = HistorialEstado::where('equipo_id', $equipo->id)->count();

        $response = $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/devolucion", [
                'condicion_devolucion' => CondicionEquipo::Bueno->value,
                'observaciones' => 'Devuelto a tiempo y en óptimas condiciones',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $prestamo->id)
            ->assertJsonPath('data.estado', EstadoPrestamo::Devuelto->value)
            ->assertJsonPath('data.estado_etiqueta', 'Devuelto')
            ->assertJsonPath('data.recibido_por', $encargado->id)
            ->assertJsonPath('data.condicion_devolucion', CondicionEquipo::Bueno->value)
            ->assertJsonPath('data.observaciones_entrega', 'Entregado con cargador original')
            ->assertJsonPath('data.observaciones_devolucion', 'Devuelto a tiempo y en óptimas condiciones')
            ->assertJsonMissingPath('data.observaciones');

        // Préstamo en base de datos
        $prestamoActualizado = Prestamo::findOrFail($prestamo->id);
        $this->assertSame(EstadoPrestamo::Devuelto, $prestamoActualizado->estado);
        $this->assertSame($encargado->id, $prestamoActualizado->recibido_por);
        $this->assertSame(CondicionEquipo::Bueno, $prestamoActualizado->condicion_devolucion);
        $this->assertSame('Entregado con cargador original', $prestamoActualizado->observaciones_entrega);
        $this->assertSame('Devuelto a tiempo y en óptimas condiciones', $prestamoActualizado->observaciones_devolucion);

        // El equipo debe regresar a 'disponible'
        $this->assertSame(Equipo::ESTADO_DISPONIBLE, $equipo->fresh()->estado);

        // Historial registrado una sola vez
        $this->assertSame($historialPrevioCount + 1, HistorialEstado::where('equipo_id', $equipo->id)->count());
        $nuevoHistorial = HistorialEstado::where('equipo_id', $equipo->id)->latest('id')->first();
        $this->assertSame(Equipo::ESTADO_EN_PRESTAMO, $nuevoHistorial->estado_anterior);
        $this->assertSame(Equipo::ESTADO_DISPONIBLE, $nuevoHistorial->estado_nuevo);
        $this->assertSame($encargado->id, $nuevoHistorial->user_id);
    }

    public function test_devolucion_con_danos_actualiza_equipo_a_mantenimiento(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_EN_PRESTAMO]);
        $prestamo = Prestamo::factory()->entregado()->create([
            'equipo_id' => $equipo->id,
            'fecha_entrega_real' => now()->subDay(),
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/devolucion", [
                'condicion_devolucion' => CondicionEquipo::ConDanos->value,
                'observaciones' => 'Bisagra rota y rayones en la tapa',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.condicion_devolucion', CondicionEquipo::ConDanos->value);

        // El equipo debe pasar a mantenimiento, NO a disponible
        $this->assertSame(Equipo::ESTADO_MANTENIMIENTO, $equipo->fresh()->estado);

        $nuevoHistorial = HistorialEstado::where('equipo_id', $equipo->id)->latest('id')->first();
        $this->assertSame(Equipo::ESTADO_EN_PRESTAMO, $nuevoHistorial->estado_anterior);
        $this->assertSame(Equipo::ESTADO_MANTENIMIENTO, $nuevoHistorial->estado_nuevo);
    }

    public function test_devolucion_con_requiere_mantenimiento_actualiza_equipo_a_mantenimiento(): void
    {
        $encargado = User::factory()->create();
        $encargado->assignRole('encargado');

        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_EN_PRESTAMO]);
        $prestamo = Prestamo::factory()->entregado()->create([
            'equipo_id' => $equipo->id,
            'fecha_entrega_real' => now()->subDay(),
        ]);

        $response = $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/devolucion", [
                'condicion_devolucion' => CondicionEquipo::RequiereMantenimiento->value,
                'observaciones' => 'El teclado no responde en varias teclas',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.condicion_devolucion', CondicionEquipo::RequiereMantenimiento->value);

        $this->assertSame(Equipo::ESTADO_MANTENIMIENTO, $equipo->fresh()->estado);
    }

    public function test_observaciones_son_obligatorias_si_la_condicion_no_es_bueno(): void
    {
        $encargado = User::factory()->create();
        $encargado->assignRole('encargado');

        $prestamo = Prestamo::factory()->entregado()->create();

        // Sin campo observaciones
        $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/devolucion", [
                'condicion_devolucion' => CondicionEquipo::ConDanos->value,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['observaciones']);

        // Observaciones solo con espacios
        $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/devolucion", [
                'condicion_devolucion' => CondicionEquipo::RequiereMantenimiento->value,
                'observaciones' => '     ',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['observaciones']);
    }

    public function test_rechaza_devolucion_si_el_prestamo_no_esta_en_estado_entregado(): void
    {
        $encargado = User::factory()->create();
        $encargado->assignRole('encargado');

        // Préstamo en estado aprobado (aún no entregado), con equipo en préstamo para validar la transición de estado
        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_EN_PRESTAMO]);
        $prestamoAprobado = Prestamo::factory()->aprobado()->create(['equipo_id' => $equipo->id]);

        $response = $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamoAprobado->id}/devolucion", [
                'condicion_devolucion' => CondicionEquipo::Bueno->value,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['estado']);

        $this->assertSame(
            'El préstamo está en estado Aprobado y no puede pasar a Devuelto.',
            $response->json('errors.estado.0')
        );
    }

    public function test_rechaza_devolucion_si_fecha_devolucion_es_anterior_a_fecha_entrega(): void
    {
        $encargado = User::factory()->create();
        $encargado->assignRole('encargado');

        $prestamo = Prestamo::factory()->entregado()->create([
            'fecha_entrega_real' => now()->subDay(),
        ]);

        $response = $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/devolucion", [
                'fecha_devolucion_real' => now()->subDays(2)->toDateTimeString(),
                'condicion_devolucion' => CondicionEquipo::Bueno->value,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['fecha_devolucion_real']);

        $this->assertSame(
            'La fecha de devolución real no puede ser anterior a la fecha de entrega real.',
            $response->json('errors.fecha_devolucion_real.0')
        );
    }

    public function test_personal_puede_listar_y_buscar_prestamos_activos(): void
    {
        $encargado = User::factory()->create();
        $encargado->assignRole('encargado');

        $solicitante1 = User::factory()->create(['name' => 'Carlos Mendoza', 'email' => 'carlos@ecci.edu.co']);
        $equipo1 = Equipo::factory()->create(['codigo' => 'PORT-001', 'nombre' => 'Laptop Dell Latitude']);
        $prestamoActivo1 = Prestamo::factory()->entregado()->create([
            'usuario_id' => $solicitante1->id,
            'equipo_id' => $equipo1->id,
        ]);

        $solicitante2 = User::factory()->create(['name' => 'Ana Gomez', 'email' => 'ana@ecci.edu.co']);
        $equipo2 = Equipo::factory()->create(['codigo' => 'TAB-002', 'nombre' => 'iPad Air']);
        $prestamoActivo2 = Prestamo::factory()->entregado()->create([
            'usuario_id' => $solicitante2->id,
            'equipo_id' => $equipo2->id,
        ]);

        // Préstamo devuelto (no debe figurar en activos)
        Prestamo::factory()->devuelto()->create();

        // 1. Listado sin filtro: retorna los 2 activos
        $resTodos = $this->actingAs($encargado, 'sanctum')->getJson('/api/prestamos/activos');
        $resTodos->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);

        // 2. Búsqueda por código de equipo
        $resCodigo = $this->actingAs($encargado, 'sanctum')->getJson('/api/prestamos/activos?buscar=PORT-001');
        $resCodigo->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $prestamoActivo1->id);

        // 3. Búsqueda por nombre de solicitante
        $resNombre = $this->actingAs($encargado, 'sanctum')->getJson('/api/prestamos/activos?buscar=Carlos');
        $resNombre->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $prestamoActivo1->id);

        // 4. Búsqueda por email de solicitante
        $resEmail = $this->actingAs($encargado, 'sanctum')->getJson('/api/prestamos/activos?buscar=ana@ecci.edu.co');
        $resEmail->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $prestamoActivo2->id);
    }

    public function test_rol_usuario_recibe_403_en_activos_y_devolucion(): void
    {
        $usuario = User::factory()->create();
        $usuario->assignRole('usuario');

        $prestamo = Prestamo::factory()->entregado()->create();

        $this->actingAs($usuario, 'sanctum')
            ->getJson('/api/prestamos/activos')
            ->assertStatus(403)
            ->assertJsonPath('message', 'No tienes permiso para realizar esta acción.');

        $this->actingAs($usuario, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/devolucion", [
                'condicion_devolucion' => CondicionEquipo::Bueno->value,
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'No tienes permiso para realizar esta acción.');
    }

    public function test_sin_token_recibe_401(): void
    {
        $prestamo = Prestamo::factory()->entregado()->create();

        $this->getJson('/api/prestamos/activos')
            ->assertStatus(401)
            ->assertJsonPath('message', 'No autenticado.');

        $this->postJson("/api/prestamos/{$prestamo->id}/devolucion", [])
            ->assertStatus(401)
            ->assertJsonPath('message', 'No autenticado.');
    }

    public function test_rechaza_fecha_devolucion_real_futura(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 15:00:00', 'America/Bogota'));

        try {
            $encargado = User::factory()->create();
            $encargado->assignRole('encargado');
            $prestamo = Prestamo::factory()->entregado()->create([
                'fecha_entrega_real' => '2026-10-15 17:00:00', // 12:00 Bogota
            ]);

            $response = $this->actingAs($encargado, 'sanctum')
                ->postJson("/api/prestamos/{$prestamo->id}/devolucion", [
                    'condicion_devolucion' => CondicionEquipo::Bueno->value,
                    'fecha_devolucion_real' => '2026-10-15 18:00:00', // 18:00 > 15:00 actual
                ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['fecha_devolucion_real']);
            $this->assertSame(
                'La fecha de devolución real no puede ser posterior al momento actual.',
                $response->json('errors.fecha_devolucion_real.0')
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_rechaza_fecha_devolucion_real_anterior_a_entrega(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 17:00:00', 'America/Bogota'));

        try {
            $encargado = User::factory()->create();
            $encargado->assignRole('encargado');
            $prestamo = Prestamo::factory()->entregado()->create([
                'fecha_entrega_real' => '2026-10-15 19:00:00', // 19:00 UTC = 14:00 Bogota
            ]);

            // Intentar registrar devolución a las 13:00 Bogota (18:00 UTC), anterior a la entrega
            $response = $this->actingAs($encargado, 'sanctum')
                ->postJson("/api/prestamos/{$prestamo->id}/devolucion", [
                    'condicion_devolucion' => CondicionEquipo::Bueno->value,
                    'fecha_devolucion_real' => '2026-10-15 13:00:00',
                ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['fecha_devolucion_real']);
            $this->assertSame(
                'La fecha de devolución real no puede ser anterior a la fecha de entrega real.',
                $response->json('errors.fecha_devolucion_real.0')
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_devolucion_con_fecha_sin_desplazamiento_se_interpreta_en_hora_colombia(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 18:00:00', 'America/Bogota'));

        try {
            $encargado = User::factory()->create();
            $encargado->assignRole('encargado');
            $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_EN_PRESTAMO]);
            $prestamo = Prestamo::factory()->entregado()->create([
                'equipo_id' => $equipo->id,
                'fecha_entrega_real' => '2026-10-15 17:00:00', // 12:00 Bogota
            ]);

            $response = $this->actingAs($encargado, 'sanctum')
                ->postJson("/api/prestamos/{$prestamo->id}/devolucion", [
                    'condicion_devolucion' => CondicionEquipo::Bueno->value,
                    'fecha_devolucion_real' => '2026-10-15 16:30:00',
                ]);

            $response->assertStatus(200)
                ->assertJsonPath('data.fecha_devolucion_real', '2026-10-15T16:30:00-05:00');

            $prestamo->refresh();
            $this->assertSame('2026-10-15 21:30:00', $prestamo->fecha_devolucion_real->format('Y-m-d H:i:s'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_rechaza_fecha_devolucion_real_con_formato_invalido(): void
    {
        $encargado = User::factory()->create();
        $encargado->assignRole('encargado');
        $prestamo = Prestamo::factory()->entregado()->create();

        $response = $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/devolucion", [
                'condicion_devolucion' => CondicionEquipo::Bueno->value,
                'fecha_devolucion_real' => '2026-10-15',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['fecha_devolucion_real']);
    }

    public function test_rechaza_devolucion_si_equipo_esta_en_estado_disponible(): void
    {
        $encargado = User::factory()->create();
        $encargado->assignRole('encargado');

        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_DISPONIBLE]);
        $prestamo = Prestamo::factory()->entregado()->create(['equipo_id' => $equipo->id]);
        $historialPrevio = HistorialEstado::where('equipo_id', $equipo->id)->count();

        $response = $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/devolucion", [
                'condicion_devolucion' => CondicionEquipo::Bueno->value,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['equipo']);

        $this->assertStringContainsString('disponible', $response->json('errors.equipo.0'));
        $this->assertSame(EstadoPrestamo::Entregado, $prestamo->fresh()->estado);
        $this->assertSame(Equipo::ESTADO_DISPONIBLE, $equipo->fresh()->estado);
        $this->assertSame($historialPrevio, HistorialEstado::where('equipo_id', $equipo->id)->count());
    }

    public function test_rechaza_devolucion_si_equipo_esta_en_mantenimiento(): void
    {
        $encargado = User::factory()->create();
        $encargado->assignRole('encargado');

        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_MANTENIMIENTO]);
        $prestamo = Prestamo::factory()->entregado()->create(['equipo_id' => $equipo->id]);
        $historialPrevio = HistorialEstado::where('equipo_id', $equipo->id)->count();

        $response = $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/devolucion", [
                'condicion_devolucion' => CondicionEquipo::Bueno->value,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['equipo']);

        $this->assertStringContainsString('mantenimiento', $response->json('errors.equipo.0'));
        $this->assertSame(EstadoPrestamo::Entregado, $prestamo->fresh()->estado);
        $this->assertSame(Equipo::ESTADO_MANTENIMIENTO, $equipo->fresh()->estado);
        $this->assertSame($historialPrevio, HistorialEstado::where('equipo_id', $equipo->id)->count());
    }

    public function test_rechaza_devolucion_si_equipo_esta_dado_de_baja(): void
    {
        $encargado = User::factory()->create();
        $encargado->assignRole('encargado');

        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_DADO_DE_BAJA]);
        $prestamo = Prestamo::factory()->entregado()->create(['equipo_id' => $equipo->id]);
        $historialPrevio = HistorialEstado::where('equipo_id', $equipo->id)->count();

        $response = $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/devolucion", [
                'condicion_devolucion' => CondicionEquipo::Bueno->value,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['equipo']);

        $this->assertStringContainsString('dado de baja', $response->json('errors.equipo.0'));
        $this->assertSame(EstadoPrestamo::Entregado, $prestamo->fresh()->estado);
        $this->assertSame(Equipo::ESTADO_DADO_DE_BAJA, $equipo->fresh()->estado);
        $this->assertSame($historialPrevio, HistorialEstado::where('equipo_id', $equipo->id)->count());
    }
}

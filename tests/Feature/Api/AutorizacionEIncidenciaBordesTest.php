<?php

namespace Tests\Feature\Api;

use App\Enums\CondicionEquipo;
use App\Models\Categoria;
use App\Models\Equipo;
use App\Models\Incidencia;
use App\Models\Prestamo;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RN-03 / KAN-292 y RN-08 / KAN-297 — Bordes de la autorización especial y de
 * la incidencia por daños.
 *
 * Validación de release develop → main (TC-SRV-043 … TC-SRV-047).
 */
class AutorizacionEIncidenciaBordesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-12-09 17:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function usuario(string $rol): User
    {
        $user = User::factory()->create();
        $user->assignRole($rol);

        return $user;
    }

    private function solicitudRestringida(): Prestamo
    {
        $categoria = Categoria::factory()->create(['nivel_restriccion' => 1]);
        $equipo = Equipo::factory()->create([
            'estado' => Equipo::ESTADO_DISPONIBLE,
            'categoria_id' => $categoria->id,
        ]);

        return Prestamo::factory()->solicitado()->create([
            'usuario_id' => $this->usuario('estudiante')->id,
            'equipo_id' => $equipo->id,
            'requiere_autorizacion' => true,
        ]);
    }

    /** TC-SRV-043 · RN-03 inválido: una solicitud ya autorizada no se autoriza dos veces. */
    public function test_una_solicitud_ya_autorizada_no_se_autoriza_dos_veces(): void
    {
        $prestamo = $this->solicitudRestringida();
        $primero = $this->usuario('encargado');

        $this->actingAs($primero, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/autorizacion")
            ->assertOk();

        $this->actingAs($this->usuario('admin'), 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/autorizacion")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['prestamo']);

        // La autorización original se conserva.
        $this->assertSame($primero->id, $prestamo->fresh()->autorizado_por);
    }

    /** TC-SRV-044 · RN-03 inválido: una solicitud rechazada no admite autorización. */
    public function test_una_solicitud_rechazada_no_admite_autorizacion(): void
    {
        $prestamo = $this->solicitudRestringida();
        $prestamo->update(['estado' => 'rechazado', 'fecha_rechazo' => now(), 'motivo_rechazo' => 'Sin cupo de laboratorio']);

        $this->actingAs($this->usuario('encargado'), 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/autorizacion")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['estado']);

        $this->assertNull($prestamo->fresh()->autorizado_por);
    }

    /** TC-SRV-045 · RN-03 límite: la categoría de nivel 2 también exige autorización al personal que la solicita. */
    public function test_la_categoria_de_nivel_2_exige_autorizacion_aunque_la_solicite_el_personal(): void
    {
        $categoria = Categoria::factory()->create(['nivel_restriccion' => 2]);
        $equipo = Equipo::factory()->create([
            'estado' => Equipo::ESTADO_DISPONIBLE,
            'categoria_id' => $categoria->id,
        ]);

        $respuesta = $this->actingAs($this->usuario('encargado'), 'sanctum')->postJson('/api/prestamos', [
            'equipo_id' => $equipo->id,
            'motivo' => 'Equipo especializado para práctica',
            'fecha_inicio' => now()->addHour()->toIso8601String(),
            'fecha_devolucion_estimada' => now()->addDays(2)->toIso8601String(),
        ]);

        $respuesta->assertCreated();
        $this->assertTrue((bool) Prestamo::findOrFail($respuesta->json('data.id'))->requiere_autorizacion);
    }

    /** TC-SRV-046 · RN-08 válido: devolver con «requiere mantenimiento» también abre incidencia y saca el equipo de circulación. */
    public function test_devolver_con_requiere_mantenimiento_abre_incidencia_y_restringe(): void
    {
        $solicitante = $this->usuario('estudiante');
        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_EN_PRESTAMO]);
        $prestamo = Prestamo::factory()->entregado()->create([
            'usuario_id' => $solicitante->id,
            'equipo_id' => $equipo->id,
            'fecha_entrega_real' => now()->subDay(),
            'fecha_devolucion_estimada' => now()->addDay(),
        ]);

        $this->actingAs($this->usuario('encargado'), 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/devolucion", [
                'condicion_devolucion' => CondicionEquipo::RequiereMantenimiento->value,
                'observaciones' => 'El teclado no responde en varias teclas.',
            ])->assertOk();

        $incidencia = Incidencia::where('prestamo_id', $prestamo->id)->firstOrFail();
        $this->assertSame(Incidencia::ESTADO_ABIERTA, $incidencia->estado);
        $this->assertSame($solicitante->id, $incidencia->user_id);
        $this->assertSame(Equipo::ESTADO_MANTENIMIENTO, $equipo->fresh()->estado);
    }

    /** TC-SRV-047 · RN-08 límite: la restricción por incidencia bloquea hasta su último segundo y libera al siguiente. */
    public function test_la_restriccion_por_incidencia_bloquea_hasta_su_ultimo_segundo(): void
    {
        $solicitante = $this->usuario('estudiante');
        $prestamo = Prestamo::factory()->devuelto()->create(['usuario_id' => $solicitante->id]);
        Incidencia::create([
            'prestamo_id' => $prestamo->id,
            'equipo_id' => $prestamo->equipo_id,
            'user_id' => $solicitante->id,
            'descripcion' => 'Pantalla rayada',
            'estado' => Incidencia::ESTADO_ABIERTA,
            'restringe_hasta' => now()->copy(),
        ]);

        $payload = fn () => [
            'equipo_id' => Equipo::factory()->create(['estado' => Equipo::ESTADO_DISPONIBLE])->id,
            'motivo' => 'Solicitud en el borde de la restricción',
            'fecha_inicio' => now()->addHour()->toIso8601String(),
            'fecha_devolucion_estimada' => now()->addDays(2)->toIso8601String(),
        ];

        $this->actingAs($solicitante, 'sanctum')->postJson('/api/prestamos', $payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['usuario_id']);

        Carbon::setTestNow(now()->addSecond());

        $this->actingAs($solicitante, 'sanctum')->postJson('/api/prestamos', $payload())
            ->assertCreated();
    }
}

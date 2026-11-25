<?php

namespace Tests\Feature\Api;

use App\Enums\CondicionEquipo;
use App\Models\Equipo;
use App\Models\Prestamo;
use App\Models\Sancion;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * RN-04 / KAN-293 — Bordes de la escala de mora y de la vigencia de la sanción.
 *
 * Validación de release develop → main (TC-SRV-032 … TC-SRV-037). Complementa
 * MoraSancionTest, que cubre 1, 2 y 6 días de retraso: aquí se prueban los
 * extremos de cada tramo de la escala por defecto (1–2 → 3, 3–5 → 7, 6+ → 15),
 * la devolución en el instante exacto y el último segundo de la sanción.
 *
 * El reloj se fija al mediodía de Bogotá (17:00 UTC) para que el conteo de
 * días no dependa de la hora a la que corra la suite.
 */
class MoraSancionBordesTest extends TestCase
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

    private function personal(): User
    {
        $user = User::factory()->create();
        $user->assignRole('encargado');

        return $user;
    }

    private function estudiante(): User
    {
        $user = User::factory()->create();
        $user->assignRole('estudiante');

        return $user;
    }

    private function prestamoEntregado(User $solicitante, Carbon $estimada): Prestamo
    {
        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_EN_PRESTAMO]);

        return Prestamo::factory()->entregado()->create([
            'usuario_id' => $solicitante->id,
            'equipo_id' => $equipo->id,
            'fecha_inicio' => $estimada->copy()->subDays(10),
            'fecha_entrega_real' => $estimada->copy()->subDays(10),
            'fecha_devolucion_estimada' => $estimada,
        ]);
    }

    private function devolverAhora(Prestamo $prestamo): TestResponse
    {
        return $this->actingAs($this->personal(), 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/devolucion", [
                'condicion_devolucion' => CondicionEquipo::Bueno->value,
            ]);
    }

    private function sancionDe(User $usuario): Sancion
    {
        return Sancion::where('user_id', $usuario->id)->firstOrFail();
    }

    /** TC-SRV-032 · RN-04 límite: 3 días de retraso abren el segundo tramo (7 días de sanción). */
    public function test_tres_dias_de_retraso_pasan_al_segundo_tramo_de_la_escala(): void
    {
        $solicitante = $this->estudiante();
        $prestamo = $this->prestamoEntregado($solicitante, now()->subDays(3));

        $this->devolverAhora($prestamo)->assertOk();

        $sancion = $this->sancionDe($solicitante);
        $this->assertSame(3, $sancion->dias_retraso);
        $this->assertSame(7, $sancion->dias_sancion);
    }

    /** TC-SRV-033 · RN-04 límite: 5 días de retraso siguen en el segundo tramo (7 días). */
    public function test_cinco_dias_de_retraso_siguen_en_el_segundo_tramo_de_la_escala(): void
    {
        $solicitante = $this->estudiante();
        $prestamo = $this->prestamoEntregado($solicitante, now()->subDays(5));

        $this->devolverAhora($prestamo)->assertOk();

        $sancion = $this->sancionDe($solicitante);
        $this->assertSame(5, $sancion->dias_retraso);
        $this->assertSame(7, $sancion->dias_sancion);
        $this->assertTrue($sancion->fecha_fin->equalTo(now()->addDays(7)));
    }

    /** TC-SRV-034 · RN-04 límite: devolver unas horas tarde el mismo día cuenta como 1 día de mora. */
    public function test_devolver_horas_tarde_el_mismo_dia_cuenta_un_dia_de_mora(): void
    {
        $solicitante = $this->estudiante();
        $prestamo = $this->prestamoEntregado($solicitante, now()->subHours(3));

        $this->devolverAhora($prestamo)->assertOk();

        $sancion = $this->sancionDe($solicitante);
        $this->assertSame(1, $sancion->dias_retraso);
        $this->assertSame(3, $sancion->dias_sancion);
    }

    /** TC-SRV-035 · RN-04 límite: devolver en el instante exacto de la fecha estimada no sanciona. */
    public function test_devolver_en_el_instante_exacto_de_la_fecha_estimada_no_sanciona(): void
    {
        $solicitante = $this->estudiante();
        $prestamo = $this->prestamoEntregado($solicitante, now()->copy());

        $this->devolverAhora($prestamo)->assertOk();

        $this->assertSame(0, Sancion::where('user_id', $solicitante->id)->count());
        $this->assertSame('devuelto', $prestamo->fresh()->estado->value);
    }

    /** TC-SRV-036 · RN-04 / RN-01 límite: en el último segundo de la sanción el usuario sigue bloqueado. */
    public function test_la_sancion_sigue_bloqueando_en_su_ultimo_segundo(): void
    {
        $solicitante = $this->estudiante();
        $this->sancionQueTerminaEn($solicitante, now()->copy());

        $this->solicitar($solicitante)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['usuario_id']);

        $this->assertSame(Sancion::ESTADO_ACTIVA, $this->sancionDe($solicitante)->estado);
    }

    /** TC-SRV-037 · RN-04 / RN-01 límite: un segundo después de terminar, la sanción queda cumplida y no bloquea. */
    public function test_un_segundo_despues_de_terminar_la_sancion_queda_cumplida_y_no_bloquea(): void
    {
        $solicitante = $this->estudiante();
        $this->sancionQueTerminaEn($solicitante, now()->copy()->subSecond());

        $this->solicitar($solicitante)->assertCreated();

        $this->assertSame(Sancion::ESTADO_CUMPLIDA, $this->sancionDe($solicitante)->estado);
    }

    private function sancionQueTerminaEn(User $usuario, Carbon $fin): Sancion
    {
        return Sancion::create([
            'user_id' => $usuario->id,
            'prestamo_id' => null,
            'motivo' => Sancion::MOTIVO_MORA,
            'dias_retraso' => 1,
            'dias_sancion' => 3,
            'fecha_inicio' => $fin->copy()->subDays(3),
            'fecha_fin' => $fin,
            'estado' => Sancion::ESTADO_ACTIVA,
        ]);
    }

    private function solicitar(User $usuario): TestResponse
    {
        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_DISPONIBLE]);

        return $this->actingAs($usuario, 'sanctum')->postJson('/api/prestamos', [
            'equipo_id' => $equipo->id,
            'motivo' => 'Solicitud en el borde de la sanción',
            'fecha_inicio' => now()->addHour()->toIso8601String(),
            'fecha_devolucion_estimada' => now()->addDays(2)->toIso8601String(),
        ]);
    }
}

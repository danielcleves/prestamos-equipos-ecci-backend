<?php

namespace Tests\Feature\Api;

use App\Models\Equipo;
use App\Models\Prestamo;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\ConfiguraParametros;
use Tests\TestCase;

/**
 * RN-02 / KAN-291 y RN-05 / KAN-294 — Qué cuenta para el cupo y qué bloquea
 * el calendario del equipo.
 *
 * Validación de release develop → main (TC-SRV-038 … TC-SRV-042). Los
 * préstamos cerrados (rechazado, devuelto) no consumen cupo ni bloquean el
 * intervalo; los vivos (solicitado, aprobado, entregado) sí, incluso por un
 * solo segundo de cruce.
 */
class CupoYSolapamientoBordesTest extends TestCase
{
    use ConfiguraParametros;
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

    private function estudiante(): User
    {
        $user = User::factory()->create();
        $user->assignRole('estudiante');

        return $user;
    }

    private function solicitar(User $usuario, Equipo $equipo, Carbon $inicio, Carbon $fin): TestResponse
    {
        return $this->actingAs($usuario, 'sanctum')->postJson('/api/prestamos', [
            'equipo_id' => $equipo->id,
            'motivo' => 'Prueba de bordes de cupo y calendario',
            'fecha_inicio' => $inicio->toIso8601String(),
            'fecha_devolucion_estimada' => $fin->toIso8601String(),
        ]);
    }

    private function disponible(): Equipo
    {
        return Equipo::factory()->create(['estado' => Equipo::ESTADO_DISPONIBLE]);
    }

    /** TC-SRV-038 · RN-02 válido: los préstamos rechazados y devueltos no consumen cupo. */
    public function test_los_prestamos_rechazados_y_devueltos_no_consumen_cupo(): void
    {
        $this->cupo('estudiante', 1);
        $solicitante = $this->estudiante();

        Prestamo::factory()->rechazado()->create(['usuario_id' => $solicitante->id]);
        Prestamo::factory()->devuelto()->create(['usuario_id' => $solicitante->id]);

        $this->solicitar($solicitante, $this->disponible(), now()->addHour(), now()->addDays(2))
            ->assertCreated();

        $this->assertSame(3, Prestamo::where('usuario_id', $solicitante->id)->count());
    }

    /** TC-SRV-039 · RN-02 inválido: un préstamo entregado sí consume cupo (cupo 1 lleno → 422). */
    public function test_un_prestamo_entregado_consume_cupo(): void
    {
        $this->cupo('estudiante', 1);
        $solicitante = $this->estudiante();

        Prestamo::factory()->entregado()->create([
            'usuario_id' => $solicitante->id,
            'equipo_id' => Equipo::factory()->create(['estado' => Equipo::ESTADO_EN_PRESTAMO])->id,
            'fecha_devolucion_estimada' => now()->addDays(2),
        ]);

        $this->solicitar($solicitante, $this->disponible(), now()->addHour(), now()->addDays(2))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['usuario_id']);

        $this->assertSame(1, Prestamo::where('usuario_id', $solicitante->id)->count());
    }

    /** TC-SRV-040 · RN-05 límite: un cruce de un solo segundo con otro préstamo vivo es rechazado. */
    public function test_un_cruce_de_un_segundo_con_otro_prestamo_es_rechazado(): void
    {
        $equipo = $this->disponible();
        $inicioOtro = now()->addDays(1);
        $finOtro = now()->addDays(3);

        Prestamo::factory()->aprobado()->create([
            'equipo_id' => $equipo->id,
            'fecha_inicio' => $inicioOtro,
            'fecha_devolucion_estimada' => $finOtro,
        ]);

        // Termina un segundo después de que empieza el otro.
        $this->solicitar($this->estudiante(), $equipo, now()->addHour(), $inicioOtro->copy()->addSecond())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['equipo_id']);

        $this->assertSame(1, Prestamo::where('equipo_id', $equipo->id)->count());
    }

    /** TC-SRV-041 · RN-05 inválido: un intervalo contenido dentro de otra solicitud pendiente es rechazado. */
    public function test_un_intervalo_contenido_dentro_de_otra_solicitud_pendiente_es_rechazado(): void
    {
        $equipo = $this->disponible();

        Prestamo::factory()->solicitado()->create([
            'equipo_id' => $equipo->id,
            'fecha_inicio' => now()->addHour(),
            'fecha_devolucion_estimada' => now()->addDays(5),
        ]);

        $this->solicitar($this->estudiante(), $equipo, now()->addDays(1), now()->addDays(2))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['equipo_id']);
    }

    /** TC-SRV-042 · RN-05 válido: un préstamo rechazado en el mismo intervalo no bloquea el calendario. */
    public function test_un_prestamo_rechazado_en_el_mismo_intervalo_no_bloquea_el_calendario(): void
    {
        $equipo = $this->disponible();
        $inicio = now()->addHour();
        $fin = now()->addDays(2);

        Prestamo::factory()->rechazado()->create([
            'equipo_id' => $equipo->id,
            'fecha_inicio' => $inicio,
            'fecha_devolucion_estimada' => $fin,
        ]);

        $this->solicitar($this->estudiante(), $equipo, $inicio, $fin)->assertCreated();

        $this->assertSame(1, Prestamo::where('equipo_id', $equipo->id)->where('estado', 'solicitado')->count());
    }
}

<?php

namespace Tests\Feature\Api;

use App\Enums\CondicionEquipo;
use App\Models\Equipo;
use App\Models\Prestamo;
use App\Models\Sancion;
use App\Models\User;
use App\Support\FechaNegocio;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * BUG-0006 — la mora de RN-04 se cuenta en días calendario de Bogotá.
 *
 * Antes se calculaba con startOfDay() sobre fechas en UTC, así que el cambio
 * de día ocurría a las 19:00 de Bogotá y un retraso de casi dos días y medio
 * se contaba como dos días en vez de tres.
 */
class MoraZonaNegocioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function personal(): User
    {
        $user = User::factory()->create();
        $user->assignRole('encargado');

        return $user;
    }

    private function enBogota(int $anio, int $mes, int $dia, int $hora, int $minuto, int $segundo = 0): Carbon
    {
        return Carbon::create($anio, $mes, $dia, $hora, $minuto, $segundo, FechaNegocio::zonaNegocio());
    }

    private function devolver(Prestamo $prestamo, Carbon $fecha): TestResponse
    {
        return $this->actingAs($this->personal(), 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/devolucion", [
                'condicion_devolucion' => CondicionEquipo::Bueno->value,
                'fecha_devolucion_real' => $fecha->toIso8601String(),
            ]);
    }

    /**
     * Caso del reporte: estimada el 10 dic a las 23:59:59 COT y devuelta el
     * 13 dic a las 00:30 COT. Son tres días calendario de retraso y, con la
     * escala por defecto (3–5 días), siete días de sanción.
     */
    public function test_el_retraso_se_cuenta_en_dias_calendario_de_bogota(): void
    {
        $solicitante = User::factory()->create();
        $solicitante->assignRole('estudiante');

        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_EN_PRESTAMO]);

        $estimada = $this->enBogota(2025, 12, 10, 23, 59, 59);
        $devolucion = $this->enBogota(2025, 12, 13, 0, 30);

        $prestamo = Prestamo::factory()->entregado()->create([
            'usuario_id' => $solicitante->id,
            'equipo_id' => $equipo->id,
            'fecha_aprobacion' => $this->enBogota(2025, 12, 9, 8, 0),
            'fecha_entrega_real' => $this->enBogota(2025, 12, 10, 8, 0),
            'fecha_devolucion_estimada' => $estimada,
        ]);

        $this->devolver($prestamo, $devolucion)->assertStatus(200);

        $sancion = Sancion::where('user_id', $solicitante->id)->firstOrFail();

        $this->assertSame(3, $sancion->dias_retraso);
        $this->assertSame(7, $sancion->dias_sancion);
    }

    /**
     * Límite inferior: devolver minutos después de la hora estimada, todavía
     * el mismo día calendario de Bogotá, cuenta como un día y no como cero.
     */
    public function test_una_devolucion_del_mismo_dia_calendario_cuenta_un_dia(): void
    {
        $solicitante = User::factory()->create();
        $solicitante->assignRole('estudiante');

        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_EN_PRESTAMO]);

        $prestamo = Prestamo::factory()->entregado()->create([
            'usuario_id' => $solicitante->id,
            'equipo_id' => $equipo->id,
            'fecha_aprobacion' => $this->enBogota(2025, 12, 9, 8, 0),
            'fecha_entrega_real' => $this->enBogota(2025, 12, 10, 8, 0),
            'fecha_devolucion_estimada' => $this->enBogota(2025, 12, 10, 18, 0),
        ]);

        $this->devolver($prestamo, $this->enBogota(2025, 12, 10, 23, 59))->assertStatus(200);

        $sancion = Sancion::where('user_id', $solicitante->id)->firstOrFail();

        $this->assertSame(1, $sancion->dias_retraso);
        $this->assertSame(3, $sancion->dias_sancion);
    }
}

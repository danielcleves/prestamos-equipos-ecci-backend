<?php

namespace Tests\Feature\Api;

use App\Enums\CondicionEquipo;
use App\Models\Equipo;
use App\Models\Sancion;
use App\Models\User;
use App\Services\SolicitudPrestamoService;
use App\Support\FechaNegocio;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG-0011 — el servicio de solicitud guarda el instante, no el reloj de pared
 * de la zona de negocio.
 *
 * El camino de la API ya normalizaba porque StorePrestamoRequest::validated()
 * usa FechaNegocio::parsear() (UTC). El hueco estaba en el servicio: acepta
 * CarbonInterface y lo guardaba tal cual, así que un Carbon 18:00 en
 * America/Bogota se persistía como 18:00 UTC, cinco horas antes del instante
 * real. La API no cambia de comportamiento; esto cubre a los llamadores
 * internos y a las factories.
 */
class SolicitudPrestamoZonaHorariaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function enBogota(int $anio, int $mes, int $dia, int $hora, int $minuto, int $segundo = 0): Carbon
    {
        return Carbon::create($anio, $mes, $dia, $hora, $minuto, $segundo, FechaNegocio::zonaNegocio());
    }

    private function solicitante(): User
    {
        $usuario = User::factory()->create();
        $usuario->assignRole('estudiante');

        return $usuario;
    }

    private function personal(): User
    {
        $usuario = User::factory()->create();
        $usuario->assignRole('encargado');

        return $usuario;
    }

    public function test_un_carbon_en_bogota_se_guarda_como_el_mismo_instante_en_utc(): void
    {
        $usuario = $this->solicitante();
        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_DISPONIBLE]);

        $inicio = $this->enBogota(2026, 11, 17, 8, 0);
        $estimada = $this->enBogota(2026, 11, 20, 18, 0);

        $prestamo = app(SolicitudPrestamoService::class)->solicitar($usuario, [
            'equipo_id' => $equipo->id,
            'motivo' => 'Práctica de laboratorio de redes',
            'fecha_inicio' => $inicio,
            'fecha_devolucion_estimada' => $estimada,
        ]);

        // 08:00 y 18:00 en Bogotá son 13:00 y 23:00 UTC: se conserva el instante.
        $this->assertSame('2026-11-17 13:00:00', $prestamo->fecha_inicio->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-20 23:00:00', $prestamo->fecha_devolucion_estimada->utc()->format('Y-m-d H:i:s'));

        $this->assertDatabaseHas('prestamos', [
            'id' => $prestamo->id,
            'fecha_inicio' => '2026-11-17 13:00:00',
            'fecha_devolucion_estimada' => '2026-11-20 23:00:00',
        ]);

        // El Carbon del llamador no se muta al normalizar la copia.
        $this->assertSame('America/Bogota', $inicio->getTimezone()->getName());
        $this->assertSame('2026-11-17 08:00:00', $inicio->format('Y-m-d H:i:s'));
        $this->assertSame('America/Bogota', $estimada->getTimezone()->getName());
        $this->assertSame('2026-11-20 18:00:00', $estimada->format('Y-m-d H:i:s'));
    }

    /**
     * Si la estimada se guardara con el reloj de pared de Bogotá, el instante
     * quedaría cinco horas antes y una devolución puntual en la hora estimada
     * se contaría como un día de retraso (tres días de sanción).
     */
    public function test_una_devolucion_en_el_instante_estimado_no_genera_sancion(): void
    {
        Carbon::setTestNow($this->enBogota(2026, 11, 20, 18, 0));

        try {
            $usuario = $this->solicitante();
            $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_DISPONIBLE]);

            $inicio = $this->enBogota(2026, 11, 18, 8, 0);
            $estimada = $this->enBogota(2026, 11, 20, 18, 0);

            $prestamo = app(SolicitudPrestamoService::class)->solicitar($usuario, [
                'equipo_id' => $equipo->id,
                'motivo' => 'Práctica de laboratorio de redes',
                'fecha_inicio' => $inicio,
                'fecha_devolucion_estimada' => $estimada,
            ]);

            $personal = $this->personal();

            $this->actingAs($personal, 'sanctum')
                ->postJson("/api/prestamos/{$prestamo->id}/aprobacion")
                ->assertStatus(200);

            $this->actingAs($personal, 'sanctum')
                ->postJson("/api/prestamos/{$prestamo->id}/entrega", [
                    'condicion_entrega' => CondicionEquipo::Bueno->value,
                ])
                ->assertStatus(200);

            $response = $this->actingAs($personal, 'sanctum')
                ->postJson("/api/prestamos/{$prestamo->id}/devolucion", [
                    'condicion_devolucion' => CondicionEquipo::Bueno->value,
                    'fecha_devolucion_real' => $estimada->toIso8601String(),
                ]);

            $response->assertStatus(200)
                // El contrato sigue devolviendo la misma hora de negocio.
                ->assertJsonPath('data.fecha_devolucion_estimada', '2026-11-20T18:00:00-05:00')
                ->assertJsonPath('data.fecha_devolucion_real', '2026-11-20T18:00:00-05:00');

            $this->assertSame(0, Sancion::where('user_id', $usuario->id)->count());
        } finally {
            Carbon::setTestNow();
        }
    }
}

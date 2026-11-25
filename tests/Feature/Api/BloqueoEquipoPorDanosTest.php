<?php

namespace Tests\Feature\Api;

use App\Enums\CondicionEquipo;
use App\Models\Equipo;
use App\Models\HistorialEstado;
use App\Models\Prestamo;
use App\Models\RevisionEquipo;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * HU-12 / KAN-129: un equipo devuelto con novedad queda bloqueado en
 * mantenimiento y no puede volver a prestarse hasta que un admin lo libere.
 *
 * La logica ya existia (DevolucionPrestamoService); estas pruebas cierran los
 * huecos de cobertura sobre sus consecuencias.
 */
class BloqueoEquipoPorDanosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function personal(string $rol = 'encargado'): User
    {
        $user = User::factory()->create();
        $user->assignRole($rol);

        return $user;
    }

    /**
     * Devuelve un equipo con la condicion indicada y retorna el equipo fresco.
     */
    private function devolverCon(CondicionEquipo $condicion, ?User $personal = null): Equipo
    {
        $personal ??= $this->personal();

        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_EN_PRESTAMO]);
        $prestamo = Prestamo::factory()->entregado()->create([
            'equipo_id' => $equipo->id,
            'fecha_entrega_real' => now()->subDay(),
        ]);

        $payload = ['condicion_devolucion' => $condicion->value];

        if ($condicion !== CondicionEquipo::Bueno) {
            $payload['observaciones'] = 'Novedad detectada en la revisión';
        }

        $this->actingAs($personal, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/devolucion", $payload)
            ->assertStatus(200);

        return $equipo->fresh();
    }

    /**
     * @return array<string, array{CondicionEquipo}>
     */
    public static function condicionesConNovedad(): array
    {
        return [
            'con daños' => [CondicionEquipo::ConDanos],
            'requiere mantenimiento' => [CondicionEquipo::RequiereMantenimiento],
        ];
    }

    #[DataProvider('condicionesConNovedad')]
    public function test_una_condicion_con_novedad_deja_el_equipo_en_mantenimiento(CondicionEquipo $condicion): void
    {
        $equipo = $this->devolverCon($condicion);

        $this->assertSame(Equipo::ESTADO_MANTENIMIENTO, $equipo->estado);
        $this->assertFalse($equipo->isDisponible());
    }

    #[DataProvider('condicionesConNovedad')]
    public function test_un_equipo_en_mantenimiento_no_puede_solicitarse(CondicionEquipo $condicion): void
    {
        $equipo = $this->devolverCon($condicion);

        $solicitante = User::factory()->create();
        $solicitante->assignRole('estudiante');

        $response = $this->actingAs($solicitante, 'sanctum')->postJson('/api/prestamos', [
            'equipo_id' => $equipo->id,
            'motivo' => 'Necesito el equipo para una práctica',
            'fecha_inicio' => now()->addHour()->format('Y-m-d H:i:s'),
            'fecha_devolucion_estimada' => now()->addDays(2)->format('Y-m-d H:i:s'),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['equipo_id']);

        $this->assertSame(
            'El equipo seleccionado se encuentra en mantenimiento y no está disponible para préstamo.',
            $response->json('errors.equipo_id.0')
        );

        $this->assertDatabaseMissing('prestamos', [
            'equipo_id' => $equipo->id,
            'usuario_id' => $solicitante->id,
        ]);
    }

    #[DataProvider('condicionesConNovedad')]
    public function test_un_equipo_en_mantenimiento_no_vuelve_solo_a_disponible(CondicionEquipo $condicion): void
    {
        $equipo = $this->devolverCon($condicion);

        // Ni el paso del tiempo ni una nueva consulta lo liberan: el unico
        // camino de vuelta es PATCH /api/equipos/{id}/estado, de admin.
        $this->travel(10)->days();

        $this->assertSame(Equipo::ESTADO_MANTENIMIENTO, $equipo->fresh()->estado);

        $encargado = $this->personal();
        $this->actingAs($encargado, 'sanctum')
            ->getJson("/api/equipos/{$equipo->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.estado', Equipo::ESTADO_MANTENIMIENTO);

        $this->assertSame(Equipo::ESTADO_MANTENIMIENTO, $equipo->fresh()->estado);
    }

    #[DataProvider('condicionesConNovedad')]
    public function test_un_equipo_en_mantenimiento_no_aparece_entre_los_disponibles(CondicionEquipo $condicion): void
    {
        $equipo = $this->devolverCon($condicion);
        $disponible = Equipo::factory()->create(['estado' => Equipo::ESTADO_DISPONIBLE]);

        $encargado = $this->personal();

        $response = $this->actingAs($encargado, 'sanctum')
            ->getJson('/api/equipos?disponible=1&per_page=100');

        $response->assertStatus(200);

        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($disponible->id, $ids);
        $this->assertNotContains($equipo->id, $ids);
    }

    #[DataProvider('condicionesConNovedad')]
    public function test_un_encargado_no_puede_liberar_el_equipo_bloqueado(CondicionEquipo $condicion): void
    {
        $equipo = $this->devolverCon($condicion);

        // Cambiar el estado del catalogo es exclusivo de admin (HU-04).
        $encargado = $this->personal();

        $this->actingAs($encargado, 'sanctum')
            ->patchJson("/api/equipos/{$equipo->id}/estado", ['estado' => Equipo::ESTADO_DISPONIBLE])
            ->assertStatus(403);

        $this->assertSame(Equipo::ESTADO_MANTENIMIENTO, $equipo->fresh()->estado);
    }

    #[DataProvider('condicionesConNovedad')]
    public function test_un_admin_libera_el_equipo_y_vuelve_a_ser_solicitable(CondicionEquipo $condicion): void
    {
        $equipo = $this->devolverCon($condicion);
        $admin = $this->personal('admin');

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/equipos/{$equipo->id}/estado", ['estado' => Equipo::ESTADO_DISPONIBLE])
            ->assertStatus(200)
            ->assertJsonPath('data.estado', Equipo::ESTADO_DISPONIBLE);

        $this->assertSame(Equipo::ESTADO_DISPONIBLE, $equipo->fresh()->estado);

        // El cambio manual tambien queda auditado
        $ultimo = HistorialEstado::where('equipo_id', $equipo->id)->latest('id')->firstOrFail();
        $this->assertSame(Equipo::ESTADO_MANTENIMIENTO, $ultimo->estado_anterior);
        $this->assertSame(Equipo::ESTADO_DISPONIBLE, $ultimo->estado_nuevo);
        $this->assertSame($admin->id, $ultimo->user_id);

        // Y ahora si se puede solicitar
        $solicitante = User::factory()->create();
        $solicitante->assignRole('estudiante');

        $this->actingAs($solicitante, 'sanctum')->postJson('/api/prestamos', [
            'equipo_id' => $equipo->id,
            'motivo' => 'Práctica de laboratorio',
            'fecha_inicio' => now()->addHour()->format('Y-m-d H:i:s'),
            'fecha_devolucion_estimada' => now()->addDays(2)->format('Y-m-d H:i:s'),
        ])->assertStatus(201);
    }

    public function test_una_devolucion_en_buen_estado_deja_el_equipo_solicitable_de_inmediato(): void
    {
        $equipo = $this->devolverCon(CondicionEquipo::Bueno);

        $this->assertSame(Equipo::ESTADO_DISPONIBLE, $equipo->estado);
        $this->assertTrue($equipo->isDisponible());

        $solicitante = User::factory()->create();
        $solicitante->assignRole('estudiante');

        $this->actingAs($solicitante, 'sanctum')->postJson('/api/prestamos', [
            'equipo_id' => $equipo->id,
            'motivo' => 'Nueva práctica tras la devolución',
            'fecha_inicio' => now()->addHour()->format('Y-m-d H:i:s'),
            'fecha_devolucion_estimada' => now()->addDays(2)->format('Y-m-d H:i:s'),
        ])->assertStatus(201);
    }

    #[DataProvider('condicionesConNovedad')]
    public function test_el_bloqueo_queda_justificado_por_la_revision_registrada(CondicionEquipo $condicion): void
    {
        $equipo = $this->devolverCon($condicion);

        // El bloqueo en mantenimiento debe poder explicarse: la revision dice
        // por que, y es el insumo de la HU-31 (incidencias).
        $revision = RevisionEquipo::where('equipo_id', $equipo->id)->firstOrFail();

        $this->assertTrue($revision->tiene_danos);
        $this->assertSame($condicion, $revision->condicion);
        $this->assertNotNull($revision->observaciones);

        $this->assertSame(1, RevisionEquipo::conDanos()->where('equipo_id', $equipo->id)->count());
    }

    public function test_una_revision_en_buen_estado_no_cuenta_como_novedad(): void
    {
        $equipo = $this->devolverCon(CondicionEquipo::Bueno);

        $revision = RevisionEquipo::where('equipo_id', $equipo->id)->firstOrFail();

        $this->assertFalse($revision->tiene_danos);
        $this->assertSame(0, RevisionEquipo::conDanos()->where('equipo_id', $equipo->id)->count());
    }
}

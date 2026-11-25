<?php

namespace Tests\Feature\Api;

use App\Models\Categoria;
use App\Models\Equipo;
use App\Models\Incidencia;
use App\Models\Prestamo;
use App\Models\Sancion;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ConfiguraParametros;
use Tests\TestCase;

/**
 * RN-01 — Elegibilidad para préstamo.
 */
class ElegibilidadPrestamoTest extends TestCase
{
    use ConfiguraParametros;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function solicitante(): User
    {
        $user = User::factory()->create();
        $user->assignRole('estudiante');

        return $user;
    }

    private function equipo(?Categoria $categoria = null): Equipo
    {
        $atributos = ['estado' => Equipo::ESTADO_DISPONIBLE];

        if ($categoria !== null) {
            $atributos['categoria_id'] = $categoria->id;
        }

        return Equipo::factory()->create($atributos);
    }

    /**
     * @return array<string, string>
     */
    private function payload(Equipo $equipo): array
    {
        return [
            'equipo_id' => (string) $equipo->id,
            'motivo' => 'Práctica de laboratorio',
            'fecha_inicio' => now()->addHour()->format('Y-m-d H:i:s'),
            'fecha_devolucion_estimada' => now()->addDays(2)->format('Y-m-d H:i:s'),
        ];
    }

    private function sancion(User $user, string $estado, \DateTimeInterface $fin): Sancion
    {
        return Sancion::create([
            'user_id' => $user->id,
            'motivo' => Sancion::MOTIVO_MORA,
            'dias_retraso' => 1,
            'dias_sancion' => 3,
            'fecha_inicio' => now()->subDay(),
            'fecha_fin' => $fin,
            'estado' => $estado,
        ]);
    }

    public function test_usuario_con_sancion_activa_no_puede_solicitar(): void
    {
        $usuario = $this->solicitante();
        $this->sancion($usuario, Sancion::ESTADO_ACTIVA, now()->addDays(3));

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/prestamos', $this->payload($this->equipo()));

        $response->assertStatus(422)->assertJsonValidationErrors(['usuario_id']);
        $this->assertStringContainsString('sanción activa', $response->json('errors.usuario_id.0'));
    }

    public function test_usuario_sin_sanciones_puede_solicitar(): void
    {
        $usuario = $this->solicitante();

        $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/prestamos', $this->payload($this->equipo()))
            ->assertStatus(201);
    }

    public function test_sancion_vencida_no_bloquea_y_queda_cumplida(): void
    {
        $usuario = $this->solicitante();
        $sancion = $this->sancion($usuario, Sancion::ESTADO_ACTIVA, now()->subDay());

        $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/prestamos', $this->payload($this->equipo()))
            ->assertStatus(201);

        $this->assertSame(Sancion::ESTADO_CUMPLIDA, $sancion->fresh()->estado);
    }

    public function test_usuario_con_prestamo_entregado_vencido_no_puede_solicitar(): void
    {
        // Umbral 0: cualquier retraso bloquea.
        $this->parametro('mora.dias_bloqueo', 0);
        $usuario = $this->solicitante();

        Prestamo::factory()->entregado()->create([
            'usuario_id' => $usuario->id,
            'fecha_devolucion_estimada' => now()->subHour(),
        ]);

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/prestamos', $this->payload($this->equipo()));

        $response->assertStatus(422)->assertJsonValidationErrors(['usuario_id']);
        $this->assertStringContainsString('vencido', $response->json('errors.usuario_id.0'));
    }

    public function test_umbral_de_mora_por_defecto_permite_un_retraso_de_horas(): void
    {
        // Por defecto el umbral es 1 día: 12 horas de retraso no bloquean.
        $usuario = $this->solicitante();

        Prestamo::factory()->entregado()->create([
            'usuario_id' => $usuario->id,
            'fecha_devolucion_estimada' => now()->subHours(12),
        ]);

        $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/prestamos', $this->payload($this->equipo()))
            ->assertStatus(201);
    }

    public function test_umbral_de_mora_por_defecto_bloquea_pasado_un_dia(): void
    {
        $usuario = $this->solicitante();

        Prestamo::factory()->entregado()->create([
            'usuario_id' => $usuario->id,
            'fecha_devolucion_estimada' => now()->subHours(25),
        ]);

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/prestamos', $this->payload($this->equipo()));

        $response->assertStatus(422)->assertJsonValidationErrors(['usuario_id']);
        $this->assertStringContainsString('1 día(s)', $response->json('errors.usuario_id.0'));
    }

    public function test_umbral_de_mora_parametrizable_permite_retrasos_menores(): void
    {
        $this->parametro('mora.dias_bloqueo', 3);
        $usuario = $this->solicitante();

        Prestamo::factory()->entregado()->create([
            'usuario_id' => $usuario->id,
            'fecha_devolucion_estimada' => now()->subDays(2),
        ]);

        $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/prestamos', $this->payload($this->equipo()))
            ->assertStatus(201);
    }

    public function test_umbral_de_mora_parametrizable_bloquea_al_superarlo(): void
    {
        $this->parametro('mora.dias_bloqueo', 3);
        $usuario = $this->solicitante();

        Prestamo::factory()->entregado()->create([
            'usuario_id' => $usuario->id,
            'fecha_devolucion_estimada' => now()->subDays(4),
        ]);

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/prestamos', $this->payload($this->equipo()));

        $response->assertStatus(422)->assertJsonValidationErrors(['usuario_id']);
        $this->assertStringContainsString('3 día(s)', $response->json('errors.usuario_id.0'));
    }

    public function test_capacitacion_exigida_por_la_categoria_bloquea_al_usuario_no_capacitado(): void
    {
        $categoria = Categoria::factory()->requiereCapacitacion()->create(['nombre' => 'Routers']);
        $usuario = $this->solicitante();

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/prestamos', $this->payload($this->equipo($categoria)));

        $response->assertStatus(422)->assertJsonValidationErrors(['usuario_id']);
        $this->assertSame(
            'Debes estar capacitado en «Routers» para solicitar este equipo.',
            $response->json('errors.usuario_id.0')
        );
    }

    public function test_capacitacion_exigida_por_la_categoria_permite_al_usuario_capacitado_en_ella(): void
    {
        $categoria = Categoria::factory()->requiereCapacitacion()->create();
        $usuario = User::factory()->capacitadoEn($categoria)->create();
        $usuario->assignRole('estudiante');

        $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/prestamos', $this->payload($this->equipo($categoria)))
            ->assertStatus(201);
    }

    public function test_categoria_de_nivel_2_solo_la_solicita_el_personal_administrativo(): void
    {
        $categoria = Categoria::create(['nombre' => 'Servidores', 'nivel_restriccion' => 2]);
        $equipo = $this->equipo($categoria);

        $usuario = $this->solicitante();
        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/prestamos', $this->payload($equipo));

        $response->assertStatus(422)->assertJsonValidationErrors(['usuario_id']);
        $this->assertStringContainsString(
            'solo puede ser solicitado por el personal administrativo',
            $response->json('errors.usuario_id.0')
        );

        // Caso válido: un encargado sí es personal administrativo.
        $encargado = User::factory()->create();
        $encargado->assignRole('encargado');

        $this->actingAs($encargado, 'sanctum')
            ->postJson('/api/prestamos', $this->payload($equipo))
            ->assertStatus(201);
    }

    public function test_incidencia_abierta_bloquea_al_solicitante(): void
    {
        $usuario = $this->solicitante();

        Incidencia::create([
            'prestamo_id' => Prestamo::factory()->entregado()->create(['usuario_id' => $usuario->id])->id,
            'equipo_id' => Equipo::factory()->create()->id,
            'user_id' => $usuario->id,
            'descripcion' => 'Daño reportado en la devolución.',
            'estado' => Incidencia::ESTADO_ABIERTA,
            'restringe_hasta' => now()->addDays(7),
        ]);

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/prestamos', $this->payload($this->equipo()));

        $response->assertStatus(422)->assertJsonValidationErrors(['usuario_id']);
        $this->assertStringContainsString('incidencia abierta', $response->json('errors.usuario_id.0'));
    }
}

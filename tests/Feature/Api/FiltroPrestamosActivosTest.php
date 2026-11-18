<?php

namespace Tests\Feature\Api;

use App\Enums\EstadoPrestamo;
use App\Models\Equipo;
use App\Models\Prestamo;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * HU-10 / KAN-119: filtro ?activos= sobre GET /api/prestamos.
 *
 * "Activo" = solicitado, aprobado o entregado (EstadoPrestamo::activos()),
 * el mismo conjunto que cuenta el cupo max_activos_por_usuario.
 */
class FiltroPrestamosActivosTest extends TestCase
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

    private function solicitante(): User
    {
        $user = User::factory()->create();
        $user->assignRole('usuario');

        return $user;
    }

    /**
     * Crea un préstamo de cada estado para el usuario indicado.
     *
     * @return array<string, Prestamo>
     */
    private function unoDeCadaEstado(User $usuario): array
    {
        return [
            'solicitado' => Prestamo::factory()->solicitado()->create(['usuario_id' => $usuario->id]),
            'aprobado' => Prestamo::factory()->aprobado()->create(['usuario_id' => $usuario->id]),
            'entregado' => Prestamo::factory()->entregado()->create(['usuario_id' => $usuario->id]),
            'rechazado' => Prestamo::factory()->rechazado()->create(['usuario_id' => $usuario->id]),
            'devuelto' => Prestamo::factory()->devuelto()->create(['usuario_id' => $usuario->id]),
        ];
    }

    // ---------------------------------------------------------------
    // La definición centralizada
    // ---------------------------------------------------------------

    public function test_el_enum_define_los_estados_activos(): void
    {
        $this->assertSame(
            [EstadoPrestamo::Solicitado, EstadoPrestamo::Aprobado, EstadoPrestamo::Entregado],
            EstadoPrestamo::activos()
        );

        $this->assertSame(
            ['solicitado', 'aprobado', 'entregado'],
            EstadoPrestamo::valoresActivos()
        );
    }

    public function test_es_activo_responde_por_cada_estado(): void
    {
        $this->assertTrue(EstadoPrestamo::Solicitado->esActivo());
        $this->assertTrue(EstadoPrestamo::Aprobado->esActivo());
        $this->assertTrue(EstadoPrestamo::Entregado->esActivo());

        // Los estados finales nunca son activos
        $this->assertFalse(EstadoPrestamo::Rechazado->esActivo());
        $this->assertFalse(EstadoPrestamo::Cancelado->esActivo());
        $this->assertFalse(EstadoPrestamo::Devuelto->esActivo());
    }

    public function test_ningun_estado_final_es_activo(): void
    {
        foreach (EstadoPrestamo::cases() as $estado) {
            if ($estado->isFinal()) {
                $this->assertFalse(
                    $estado->esActivo(),
                    "El estado final {$estado->value} no debería contar como activo."
                );
            }
        }
    }

    // ---------------------------------------------------------------
    // El filtro
    // ---------------------------------------------------------------

    public function test_activos_1_devuelve_solo_los_prestamos_vivos(): void
    {
        $usuario = $this->solicitante();
        $p = $this->unoDeCadaEstado($usuario);

        $response = $this->actingAs($usuario, 'sanctum')
            ->getJson('/api/prestamos?activos=1');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 3);

        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($p['solicitado']->id, $ids);
        $this->assertContains($p['aprobado']->id, $ids);
        $this->assertContains($p['entregado']->id, $ids);
        $this->assertNotContains($p['rechazado']->id, $ids);
        $this->assertNotContains($p['devuelto']->id, $ids);
    }

    public function test_activos_0_devuelve_solo_los_prestamos_cerrados(): void
    {
        $usuario = $this->solicitante();
        $p = $this->unoDeCadaEstado($usuario);

        $response = $this->actingAs($usuario, 'sanctum')
            ->getJson('/api/prestamos?activos=0');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);

        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($p['rechazado']->id, $ids);
        $this->assertContains($p['devuelto']->id, $ids);
        $this->assertNotContains($p['solicitado']->id, $ids);
        $this->assertNotContains($p['aprobado']->id, $ids);
        $this->assertNotContains($p['entregado']->id, $ids);
    }

    public function test_sin_el_parametro_no_filtra_nada(): void
    {
        $usuario = $this->solicitante();
        $this->unoDeCadaEstado($usuario);

        $this->actingAs($usuario, 'sanctum')
            ->getJson('/api/prestamos')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 5);
    }

    public function test_parametro_activos_vacio_no_filtra(): void
    {
        $usuario = $this->solicitante();
        $this->unoDeCadaEstado($usuario);

        // Mismo criterio que ?estado= vacío: no filtra, no es un error
        $this->actingAs($usuario, 'sanctum')
            ->getJson('/api/prestamos?activos=')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 5);
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function formasDeVerdadero(): array
    {
        return [
            '1' => ['1', 3],
            'true' => ['true', 3],
            '0' => ['0', 2],
            'false' => ['false', 2],
        ];
    }

    #[DataProvider('formasDeVerdadero')]
    public function test_interpreta_las_formas_booleanas_habituales(string $valor, int $esperado): void
    {
        $usuario = $this->solicitante();
        $this->unoDeCadaEstado($usuario);

        $this->actingAs($usuario, 'sanctum')
            ->getJson("/api/prestamos?activos={$valor}")
            ->assertStatus(200)
            ->assertJsonPath('meta.total', $esperado);
    }

    // ---------------------------------------------------------------
    // Combinación con el filtro por usuario y con ?estado=
    // ---------------------------------------------------------------

    public function test_el_filtro_se_combina_con_el_aislamiento_por_usuario(): void
    {
        $mio = $this->solicitante();
        $ajeno = $this->solicitante();

        $mios = $this->unoDeCadaEstado($mio);
        $this->unoDeCadaEstado($ajeno);

        $response = $this->actingAs($mio, 'sanctum')
            ->getJson('/api/prestamos?activos=1');

        $response->assertStatus(200)
            // Solo los 3 activos MÍOS, no los 6 activos que hay en total
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 3);

        foreach ($response->json('data') as $item) {
            $this->assertSame($mio->id, $item['usuario_id']);
        }

        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($mios['entregado']->id, $ids);
    }

    public function test_el_personal_ve_los_activos_de_todos(): void
    {
        $encargado = $this->personal();
        $this->unoDeCadaEstado($this->solicitante());
        $this->unoDeCadaEstado($this->solicitante());

        $this->actingAs($encargado, 'sanctum')
            ->getJson('/api/prestamos?activos=1')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 6);
    }

    public function test_estado_y_activos_se_aplican_como_interseccion_compatible(): void
    {
        $usuario = $this->solicitante();
        $p = $this->unoDeCadaEstado($usuario);

        // 'aprobado' SÍ es activo: la intersección deja ese único préstamo
        $this->actingAs($usuario, 'sanctum')
            ->getJson('/api/prestamos?estado=aprobado&activos=1')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $p['aprobado']->id);
    }

    public function test_estado_y_activos_contradictorios_devuelven_lista_vacia(): void
    {
        $usuario = $this->solicitante();
        $this->unoDeCadaEstado($usuario);

        // 'devuelto' NUNCA es activo: la intersección es vacía, no un error
        $this->actingAs($usuario, 'sanctum')
            ->getJson('/api/prestamos?estado=devuelto&activos=1')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);

        // Y al revés: un estado activo combinado con activos=0
        $this->actingAs($usuario, 'sanctum')
            ->getJson('/api/prestamos?estado=entregado&activos=0')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_un_estado_invalido_sigue_dando_422_aunque_venga_con_activos(): void
    {
        $usuario = $this->solicitante();

        $this->actingAs($usuario, 'sanctum')
            ->getJson('/api/prestamos?estado=inventado&activos=1')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['estado']);
    }

    public function test_el_filtro_convive_con_la_paginacion(): void
    {
        $usuario = $this->solicitante();
        $this->unoDeCadaEstado($usuario);

        $response = $this->actingAs($usuario, 'sanctum')
            ->getJson('/api/prestamos?activos=1&per_page=2');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_sin_token_recibe_401(): void
    {
        $this->getJson('/api/prestamos?activos=1')
            ->assertStatus(401)
            ->assertJsonPath('message', 'No autenticado.');
    }

    // ---------------------------------------------------------------
    // Coherencia con el cupo y con /prestamos/activos
    // ---------------------------------------------------------------

    public function test_el_conteo_de_activos_coincide_con_el_cupo_del_usuario(): void
    {
        config(['prestamos.max_activos_por_usuario' => 3]);

        $usuario = $this->solicitante();
        $this->unoDeCadaEstado($usuario);

        // El usuario ya tiene 3 activos: el listado lo refleja...
        $this->actingAs($usuario, 'sanctum')
            ->getJson('/api/prestamos?activos=1')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 3);

        // ...y el servicio de solicitud cuenta lo mismo, así que rechaza una más.
        $response = $this->actingAs($usuario, 'sanctum')->postJson('/api/prestamos', [
            'equipo_id' => Equipo::factory()->create()->id,
            'motivo' => 'Una solicitud por encima del cupo',
            'fecha_inicio' => now()->addHour()->format('Y-m-d H:i:s'),
            'fecha_devolucion_estimada' => now()->addDays(2)->format('Y-m-d H:i:s'),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['usuario_id']);

        $this->assertStringContainsString('límite máximo de 3', $response->json('errors.usuario_id.0'));
    }

    public function test_activos_1_es_mas_amplio_que_el_endpoint_prestamos_activos(): void
    {
        $encargado = $this->personal();
        $usuario = $this->solicitante();
        $p = $this->unoDeCadaEstado($usuario);

        // ?activos=1 incluye solicitado, aprobado y entregado
        $this->actingAs($encargado, 'sanctum')
            ->getJson('/api/prestamos?activos=1')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 3);

        // /prestamos/activos (HU-11) es más estrecho: solo los entregado
        $soloEntregados = $this->actingAs($encargado, 'sanctum')
            ->getJson('/api/prestamos/activos');

        $soloEntregados->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $p['entregado']->id);
    }
}

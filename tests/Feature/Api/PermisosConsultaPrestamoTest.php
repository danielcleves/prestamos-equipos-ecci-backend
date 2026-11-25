<?php

namespace Tests\Feature\Api;

use App\Models\Equipo;
use App\Models\Prestamo;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * HU-10 / KAN-122: permisos de consulta. Un solicitante solo alcanza sus
 * propios préstamos, y ningún parámetro de la query string lo cambia.
 */
class PermisosConsultaPrestamoTest extends TestCase
{
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

    private function personal(string $rol = 'encargado'): User
    {
        $user = User::factory()->create();
        $user->assignRole($rol);

        return $user;
    }

    // ---------------------------------------------------------------
    // KAN-122: permisos de consulta
    // ---------------------------------------------------------------

    public function test_un_usuario_solo_obtiene_sus_propios_prestamos(): void
    {
        $mio = $this->solicitante();
        $ajeno = $this->solicitante();

        $mios = Prestamo::factory()->count(2)->entregado()->create(['usuario_id' => $mio->id]);
        $ajenos = Prestamo::factory()->count(3)->entregado()->create(['usuario_id' => $ajeno->id]);

        $response = $this->actingAs($mio, 'sanctum')->getJson('/api/prestamos');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);

        $ids = array_column($response->json('data'), 'id');

        foreach ($mios as $prestamo) {
            $this->assertContains($prestamo->id, $ids);
        }
        foreach ($ajenos as $prestamo) {
            $this->assertNotContains($prestamo->id, $ids);
        }

        foreach ($response->json('data') as $item) {
            $this->assertSame($mio->id, $item['usuario_id']);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function parametrosDeSuplantacion(): array
    {
        return [
            'usuario_id' => ['usuario_id'],
            'user_id' => ['user_id'],
            'solicitante_id' => ['solicitante_id'],
            'usuario' => ['usuario'],
        ];
    }

    #[DataProvider('parametrosDeSuplantacion')]
    public function test_inyectar_un_parametro_de_usuario_no_deja_ver_los_ajenos(string $parametro): void
    {
        $mio = $this->solicitante();
        $ajeno = $this->solicitante();

        Prestamo::factory()->entregado()->create(['usuario_id' => $mio->id]);
        Prestamo::factory()->count(4)->entregado()->create(['usuario_id' => $ajeno->id]);

        $response = $this->actingAs($mio, 'sanctum')
            ->getJson("/api/prestamos?{$parametro}={$ajeno->id}");

        $response->assertStatus(200)
            // Sigue viendo solo el suyo: el filtro por usuario lo impone el
            // servidor y no se puede sobrescribir desde la query string.
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.usuario_id', $mio->id);
    }

    public function test_el_parametro_inyectado_tampoco_funciona_combinado_con_otros_filtros(): void
    {
        $mio = $this->solicitante();
        $ajeno = $this->solicitante();

        Prestamo::factory()->entregado()->create(['usuario_id' => $mio->id]);
        Prestamo::factory()->count(3)->entregado()->create(['usuario_id' => $ajeno->id]);

        $this->actingAs($mio, 'sanctum')
            ->getJson("/api/prestamos?usuario_id={$ajeno->id}&estado=entregado&activos=1")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.usuario_id', $mio->id);
    }

    public function test_el_detalle_de_un_prestamo_ajeno_da_404_sin_revelar_datos(): void
    {
        $mio = $this->solicitante();
        $ajeno = $this->solicitante();

        $equipoAjeno = Equipo::factory()->create([
            'codigo' => 'SECRETO-001',
            'nombre' => 'Equipo que no debe verse',
        ]);
        $prestamoAjeno = Prestamo::factory()->devuelto()->create([
            'usuario_id' => $ajeno->id,
            'equipo_id' => $equipoAjeno->id,
            'motivo' => 'Motivo confidencial del otro usuario',
        ]);

        $response = $this->actingAs($mio, 'sanctum')
            ->getJson("/api/prestamos/{$prestamoAjeno->id}");

        // 404 y no 403: un 403 confirmaría que el préstamo existe.
        $response->assertStatus(404)
            ->assertJsonPath('message', 'Recurso no encontrado.');

        $cuerpo = $response->getContent();
        $this->assertStringNotContainsString('SECRETO-001', $cuerpo);
        $this->assertStringNotContainsString('Equipo que no debe verse', $cuerpo);
        $this->assertStringNotContainsString('Motivo confidencial', $cuerpo);
        $this->assertStringNotContainsString($ajeno->email, $cuerpo);
    }

    public function test_un_prestamo_ajeno_y_uno_inexistente_responden_igual(): void
    {
        $mio = $this->solicitante();
        $ajeno = $this->solicitante();
        $prestamoAjeno = Prestamo::factory()->entregado()->create(['usuario_id' => $ajeno->id]);

        $resAjeno = $this->actingAs($mio, 'sanctum')->getJson("/api/prestamos/{$prestamoAjeno->id}");
        $resInexistente = $this->actingAs($mio, 'sanctum')->getJson('/api/prestamos/999999');

        // Respuestas indistinguibles: no se puede deducir qué ids existen.
        $this->assertSame($resInexistente->getStatusCode(), $resAjeno->getStatusCode());
        $this->assertSame($resInexistente->json(), $resAjeno->json());
    }

    public function test_el_solicitante_si_ve_el_detalle_del_suyo(): void
    {
        $mio = $this->solicitante();
        $prestamo = Prestamo::factory()->entregado()->create(['usuario_id' => $mio->id]);

        $this->actingAs($mio, 'sanctum')
            ->getJson("/api/prestamos/{$prestamo->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $prestamo->id)
            ->assertJsonPath('data.usuario_id', $mio->id);
    }

    public function test_el_personal_si_puede_ver_el_prestamo_de_cualquiera(): void
    {
        $encargado = $this->personal();
        $ajeno = $this->solicitante();
        $prestamo = Prestamo::factory()->entregado()->create(['usuario_id' => $ajeno->id]);

        $this->actingAs($encargado, 'sanctum')
            ->getJson("/api/prestamos/{$prestamo->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $prestamo->id);
    }

    public function test_sin_token_el_listado_y_el_detalle_dan_401(): void
    {
        $prestamo = Prestamo::factory()->entregado()->create();

        $this->getJson('/api/prestamos')
            ->assertStatus(401)
            ->assertJsonPath('message', 'No autenticado.');

        $this->getJson("/api/prestamos/{$prestamo->id}")
            ->assertStatus(401)
            ->assertJsonPath('message', 'No autenticado.');

        $this->getJson('/api/prestamos?activos=1')
            ->assertStatus(401);
    }

    public function test_un_usuario_desactivado_no_puede_consultar(): void
    {
        $usuario = $this->solicitante();
        Prestamo::factory()->entregado()->create(['usuario_id' => $usuario->id]);

        $usuario->update(['is_active' => false]);

        // Un usuario desactivado no puede obtener token nuevo
        $this->postJson('/api/login', [
            'email' => $usuario->email,
            'password' => 'password',
        ])->assertStatus(422);
    }
}

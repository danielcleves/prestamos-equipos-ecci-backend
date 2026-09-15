<?php

namespace Tests\Feature\Api;

use App\Models\Equipo;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ErrorResponsesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function actingAsUsuario(): User
    {
        $usuario = User::factory()->create();
        $usuario->assignRole('usuario');

        Sanctum::actingAs($usuario);

        return $usuario;
    }

    public function test_modelo_no_encontrado_devuelve_404_con_mensaje_estandar(): void
    {
        $this->actingAsUsuario();

        $response = $this->getJson('/api/equipos/999999');

        $response->assertStatus(404)
            ->assertExactJson(['message' => 'Recurso no encontrado.']);

        $this->assertStringNotContainsString('App\\Models', $response->getContent());
    }

    public function test_equipo_dado_de_baja_consultado_por_usuario_devuelve_404_con_mismo_json(): void
    {
        $this->actingAsUsuario();
        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_DADO_DE_BAJA]);

        $response = $this->getJson("/api/equipos/{$equipo->id}");

        $response->assertStatus(404)
            ->assertExactJson(['message' => 'Recurso no encontrado.']);

        $this->assertStringNotContainsString('App\\Models', $response->getContent());
    }

    public function test_ruta_api_inexistente_con_token_devuelve_404_con_mensaje_estandar(): void
    {
        $this->actingAsUsuario();

        $response = $this->getJson('/api/ruta-que-no-existe');

        $response->assertStatus(404)
            ->assertExactJson(['message' => 'Recurso no encontrado.']);
    }

    public function test_peticion_sin_token_devuelve_401_con_mensaje_estandar(): void
    {
        $response = $this->getJson('/api/equipos');

        $response->assertStatus(401)
            ->assertExactJson(['message' => 'No autenticado.']);
    }

    public function test_token_invalido_devuelve_401_con_mensaje_estandar(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer token-falso')
            ->getJson('/api/equipos');

        $response->assertStatus(401)
            ->assertExactJson(['message' => 'No autenticado.']);
    }

    public function test_endpoint_con_role_admin_llamado_por_usuario_devuelve_403_estandar(): void
    {
        $this->actingAsUsuario();

        // POST /api/equipos requiere role:admin
        $response = $this->postJson('/api/equipos', [
            'codigo' => 'EQ-X01',
            'nombre' => 'Laptop Prueba',
            'categoria_id' => 1,
        ]);

        $response->assertStatus(403)
            ->assertExactJson(['message' => 'No tienes permiso para realizar esta acción.']);
    }

    public function test_metodo_no_permitido_en_ruta_existente_devuelve_405_estandar(): void
    {
        $this->actingAsUsuario();
        $equipo = Equipo::factory()->create();

        // /api/equipos/{equipo} solo soporta GET
        $response = $this->postJson("/api/equipos/{$equipo->id}", []);

        $response->assertStatus(405)
            ->assertExactJson(['message' => 'Método no permitido.']);
    }

    public function test_error_500_con_debug_desactivado_devuelve_mensaje_estandar(): void
    {
        config(['app.debug' => false]);

        Route::get('/api/test-error-500', function () {
            throw new \RuntimeException('detalle interno');
        });

        $response = $this->getJson('/api/test-error-500');

        $response->assertStatus(500)
            ->assertExactJson(['message' => 'Error interno del servidor.']);

        $this->assertStringNotContainsString('detalle interno', $response->getContent());
    }

    public function test_ruta_web_inexistente_no_devuelve_json_de_la_api(): void
    {
        $response = $this->get('/ruta-web-inexistente');

        $response->assertStatus(404);
        $this->assertNotSame(
            json_encode(['message' => 'Recurso no encontrado.']),
            $response->getContent()
        );
    }

    public function test_exceder_limite_de_peticiones_devuelve_429_estandar_con_cabecera_retry_after(): void
    {
        Cache::flush();

        // /api/login tiene middleware throttle:6,1
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/login', [
                'email' => 'intento@ecci.edu.co',
                'password' => 'invalida',
            ]);
        }

        $response = $this->postJson('/api/login', [
            'email' => 'intento@ecci.edu.co',
            'password' => 'invalida',
        ]);

        $response->assertStatus(429)
            ->assertExactJson(['message' => 'Demasiadas solicitudes. Intenta de nuevo más tarde.'])
            ->assertHeader('Retry-After');

        Cache::flush();
    }

    public function test_otra_excepcion_http_4xx_devuelve_mensaje_estandar_con_status_original(): void
    {
        Route::get('/api/test-error-409', function () {
            abort(409);
        });

        $response = $this->getJson('/api/test-error-409');

        $response->assertStatus(409)
            ->assertExactJson(['message' => 'La solicitud no pudo procesarse.']);
    }
}

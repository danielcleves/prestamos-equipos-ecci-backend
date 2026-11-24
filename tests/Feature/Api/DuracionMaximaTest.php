<?php

namespace Tests\Feature\Api;

use App\Models\Categoria;
use App\Models\Equipo;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ConfiguraParametros;
use Tests\TestCase;

/**
 * RN-06 — Duración máxima por categoría y por rol.
 */
class DuracionMaximaTest extends TestCase
{
    use ConfiguraParametros;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function usuario(string $rol = 'usuario'): User
    {
        $user = User::factory()->create();
        $user->assignRole($rol);

        return $user;
    }

    private function equipo(Categoria $categoria): Equipo
    {
        return Equipo::factory()->create([
            'categoria_id' => $categoria->id,
            'estado' => Equipo::ESTADO_DISPONIBLE,
        ]);
    }

    private function categoria(int $dias): Categoria
    {
        $categoria = Categoria::create(['nombre' => 'Categoría '.uniqid()]);

        $this->parametro('duracion.categorias', [
            (string) $categoria->id => $dias,
        ]);

        return $categoria;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Equipo $equipo, int $dias): array
    {
        $inicio = now()->addDay()->setTime(8, 0, 0);

        return [
            'equipo_id' => $equipo->id,
            'motivo' => 'Proyecto con duración controlada',
            'fecha_inicio' => $inicio->format('Y-m-d H:i:s'),
            'fecha_devolucion_estimada' => $inicio->copy()->addDays($dias)->setTime(18, 0, 0)->format('Y-m-d H:i:s'),
        ];
    }

    public function test_la_duracion_exacta_es_permitida(): void
    {
        $categoria = $this->categoria(4);
        $this->parametro('duracion.roles_extra', ['usuario' => 1]);
        $equipo = $this->equipo($categoria);

        $this->actingAs($this->usuario(), 'sanctum')
            ->postJson('/api/prestamos', $this->payload($equipo, 5))
            ->assertStatus(201);
    }

    public function test_un_dia_mas_que_la_duracion_maxima_es_rechazado(): void
    {
        $categoria = $this->categoria(4);
        $this->parametro('duracion.roles_extra', ['usuario' => 1]);
        $equipo = $this->equipo($categoria);

        $response = $this->actingAs($this->usuario(), 'sanctum')
            ->postJson('/api/prestamos', $this->payload($equipo, 6));

        $response->assertStatus(422)->assertJsonValidationErrors(['fecha_devolucion_estimada']);
        $this->assertStringContainsString('no puede superar los 5 días', $response->json('errors.fecha_devolucion_estimada.0'));
    }

    public function test_la_categoria_del_equipo_cambia_el_limite(): void
    {
        $this->parametro('duracion.roles_extra', ['usuario' => 0]);

        $corta = Categoria::create(['nombre' => 'Corta '.uniqid()]);
        $larga = Categoria::create(['nombre' => 'Larga '.uniqid()]);

        $this->parametro('duracion.categorias', [
            (string) $corta->id => 3,
            (string) $larga->id => 10,
        ]);

        $usuario = $this->usuario();

        $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/prestamos', $this->payload($this->equipo($corta), 6))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fecha_devolucion_estimada']);

        $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/prestamos', $this->payload($this->equipo($larga), 6))
            ->assertStatus(201);
    }

    public function test_el_rol_del_solicitante_cambia_el_limite(): void
    {
        $categoria = $this->categoria(5);
        $this->parametro('duracion.roles_extra', ['usuario' => 0, 'encargado' => 2]);

        $usuario = $this->usuario('usuario');
        $encargado = $this->usuario('encargado');
        $equipoUsuario = $this->equipo($categoria);
        $equipoEncargado = $this->equipo($categoria);

        // 6 días: el usuario no llega (5), el encargado sí (7).
        $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/prestamos', $this->payload($equipoUsuario, 6))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fecha_devolucion_estimada']);

        $this->actingAs($encargado, 'sanctum')
            ->postJson('/api/prestamos', $this->payload($equipoEncargado, 6))
            ->assertStatus(201);
    }

    public function test_el_detalle_del_equipo_expone_la_duracion_maxima_del_usuario(): void
    {
        $categoria = $this->categoria(4);
        $this->parametro('duracion.roles_extra', ['usuario' => 1]);
        $equipo = $this->equipo($categoria);

        $this->actingAs($this->usuario(), 'sanctum')
            ->getJson("/api/equipos/{$equipo->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.duracion_maxima_dias', 5);
    }
}

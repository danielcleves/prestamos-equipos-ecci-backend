<?php

namespace Tests\Feature\Api;

use App\Models\Categoria;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RN-01 — la capacitación es por categoría de equipo y se administra desde el
 * CRUD de usuarios (campo «capacitaciones», lista de ids de categoría).
 */
class UsuarioCapacitacionesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function estudiante(Categoria ...$capacitadoEn): User
    {
        $user = User::factory()->capacitadoEn(...$capacitadoEn)->create();
        $user->assignRole('estudiante');

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function alta(array $extra = []): array
    {
        return [
            'name' => 'Nueva Persona',
            'email' => 'nueva.persona@ecci.edu.co',
            'password' => 'password123',
            'role' => 'estudiante',
            ...$extra,
        ];
    }

    public function test_post_usuarios_sin_capacitaciones_crea_al_usuario_sin_ninguna(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/usuarios', $this->alta())
            ->assertCreated()
            ->assertJsonPath('data.capacitaciones', [])
            ->assertJsonMissingPath('data.capacitado');

        $this->assertDatabaseCount('capacitaciones', 0);
    }

    public function test_post_usuarios_otorga_las_capacitaciones_a_nombre_del_admin(): void
    {
        $this->freezeSecond();
        $admin = $this->admin();
        $routers = Categoria::factory()->create(['nombre' => 'Routers']);
        $servidores = Categoria::factory()->create(['nombre' => 'Servidores']);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/usuarios', $this->alta(['capacitaciones' => [$servidores->id, $routers->id]]))
            ->assertCreated();

        // Ordenadas por nombre y con la fecha en que se otorgaron.
        $response->assertExactJsonStructure(['data' => [
            'id', 'name', 'email', 'is_active', 'capacitaciones' => ['*' => ['categoria_id', 'nombre', 'otorgada_en']],
            'roles', 'rol_principal', 'rol_etiqueta', 'sancion_activa',
        ]])
            ->assertJsonPath('data.capacitaciones.0.categoria_id', $routers->id)
            ->assertJsonPath('data.capacitaciones.0.nombre', 'Routers')
            ->assertJsonPath('data.capacitaciones.0.otorgada_en', now()->setTimezone('America/Bogota')->toIso8601String())
            ->assertJsonPath('data.capacitaciones.1.categoria_id', $servidores->id);

        $usuarioId = $response->json('data.id');
        $this->assertDatabaseHas('capacitaciones', ['user_id' => $usuarioId, 'categoria_id' => $routers->id, 'otorgada_por' => $admin->id]);
        $this->assertDatabaseHas('capacitaciones', ['user_id' => $usuarioId, 'categoria_id' => $servidores->id, 'otorgada_por' => $admin->id]);
    }

    public function test_put_usuarios_sincroniza_y_conserva_la_fecha_y_el_autor_de_las_existentes(): void
    {
        $routers = Categoria::factory()->create(['nombre' => 'Routers']);
        $servidores = Categoria::factory()->create(['nombre' => 'Servidores']);
        $portatiles = Categoria::factory()->create(['nombre' => 'Portátiles']);

        // Capacitado en Routers hace 10 días por otro admin; también en Portátiles.
        $otroAdmin = $this->admin();
        $usuario = $this->estudiante();
        $this->travel(-10)->days();
        $usuario->categoriasCapacitadas()->attach($routers->id, ['otorgada_por' => $otroAdmin->id]);
        $this->travelBack();
        $usuario->categoriasCapacitadas()->attach($portatiles->id);
        $fechaRouters = DB::table('capacitaciones')->where('categoria_id', $routers->id)->value('created_at');

        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/usuarios/{$usuario->id}", ['capacitaciones' => [$routers->id, $servidores->id]])
            ->assertOk()
            ->assertJsonCount(2, 'data.capacitaciones')
            ->assertJsonPath('data.capacitaciones.0.nombre', 'Routers')
            ->assertJsonPath('data.capacitaciones.1.nombre', 'Servidores');

        // La existente conserva fecha y autor; la nueva queda a nombre del
        // admin autenticado; la omitida se retira.
        $this->assertDatabaseHas('capacitaciones', [
            'user_id' => $usuario->id, 'categoria_id' => $routers->id,
            'otorgada_por' => $otroAdmin->id, 'created_at' => $fechaRouters,
        ]);
        $this->assertDatabaseHas('capacitaciones', [
            'user_id' => $usuario->id, 'categoria_id' => $servidores->id, 'otorgada_por' => $admin->id,
        ]);
        $this->assertDatabaseMissing('capacitaciones', ['user_id' => $usuario->id, 'categoria_id' => $portatiles->id]);
    }

    public function test_put_usuarios_con_lista_vacia_retira_todas_las_capacitaciones(): void
    {
        $routers = Categoria::factory()->create();
        $usuario = $this->estudiante($routers);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/usuarios/{$usuario->id}", ['capacitaciones' => []])
            ->assertOk()
            ->assertJsonPath('data.capacitaciones', []);

        $this->assertDatabaseCount('capacitaciones', 0);
    }

    public function test_put_usuarios_sin_el_campo_no_toca_las_capacitaciones(): void
    {
        $routers = Categoria::factory()->create();
        $usuario = $this->estudiante($routers);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/usuarios/{$usuario->id}", ['name' => 'Nombre Nuevo'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nombre Nuevo')
            ->assertJsonPath('data.capacitaciones.0.categoria_id', $routers->id);

        $this->assertDatabaseHas('capacitaciones', ['user_id' => $usuario->id, 'categoria_id' => $routers->id]);
    }

    public function test_capacitaciones_rechaza_ids_inexistentes(): void
    {
        $usuario = $this->estudiante();

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/usuarios/{$usuario->id}", ['capacitaciones' => [999]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['capacitaciones.0' => 'La categoría «999» no existe.']);

        $this->assertDatabaseCount('capacitaciones', 0);
    }

    public function test_capacitaciones_rechaza_ids_duplicados(): void
    {
        $routers = Categoria::factory()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/usuarios', $this->alta(['capacitaciones' => [$routers->id, $routers->id]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['capacitaciones.0', 'capacitaciones.1']);

        $this->assertDatabaseMissing('users', ['email' => 'nueva.persona@ecci.edu.co']);
    }

    public function test_capacitaciones_rechaza_lo_que_no_es_una_lista_de_enteros(): void
    {
        $usuario = $this->estudiante();
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/usuarios/{$usuario->id}", ['capacitaciones' => 'routers'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['capacitaciones' => 'Las capacitaciones deben ser una lista de ids de categoría.']);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/usuarios/{$usuario->id}", ['capacitaciones' => ['abc']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['capacitaciones.0' => 'Cada capacitación debe ser el id numérico de una categoría.']);
    }

    public function test_el_campo_capacitado_retirado_se_ignora(): void
    {
        $usuario = $this->estudiante();

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/usuarios/{$usuario->id}", ['capacitado' => true])
            ->assertOk()
            ->assertJsonPath('data.capacitaciones', [])
            ->assertJsonMissingPath('data.capacitado');
    }

    public function test_solo_el_admin_puede_otorgar_capacitaciones(): void
    {
        $routers = Categoria::factory()->create();
        $usuario = $this->estudiante();

        foreach (['encargado', 'estudiante'] as $rol) {
            $actor = User::factory()->create();
            $actor->assignRole($rol);

            $this->actingAs($actor, 'sanctum')
                ->putJson("/api/usuarios/{$usuario->id}", ['capacitaciones' => [$routers->id]])
                ->assertForbidden();

            $this->actingAs($actor, 'sanctum')
                ->postJson('/api/usuarios', $this->alta(['capacitaciones' => [$routers->id]]))
                ->assertForbidden();
        }

        $this->assertDatabaseCount('capacitaciones', 0);
    }

    public function test_el_listado_de_usuarios_no_consulta_capacitaciones_por_fila(): void
    {
        $admin = $this->admin();
        $routers = Categoria::factory()->create();

        $consultas = function () use ($admin): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($admin, 'sanctum')->getJson('/api/usuarios')->assertOk();
            $total = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $total;
        };

        $this->estudiante($routers);
        // La primera petición además carga los roles del admin autenticado.
        $consultas();
        $conUno = $consultas();

        $this->estudiante($routers);
        $this->estudiante($routers);
        $this->estudiante();

        $this->assertSame($conUno, $consultas());
    }

    public function test_borrar_la_categoria_borra_sus_capacitaciones(): void
    {
        $routers = Categoria::factory()->create();
        $this->estudiante($routers);

        $routers->delete();

        $this->assertDatabaseCount('capacitaciones', 0);
    }
}

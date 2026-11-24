<?php

namespace Tests\Feature\Api;

use App\Models\Categoria;
use App\Models\Configuracion;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Endpoints de configuración de los parámetros de la iteración 2.
 */
class ConfiguracionTest extends TestCase
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

    public function test_get_devuelve_los_parametros_agrupados_con_sus_valores_por_defecto(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/configuracion')
            ->assertStatus(200)
            ->assertJsonPath('data.cupos.roles.usuario', 3)
            ->assertJsonPath('data.cupos.roles.encargado', 5)
            ->assertJsonPath('data.cupos.roles.admin', 10)
            ->assertJsonPath('data.duracion.roles_extra.encargado', 2)
            ->assertJsonPath('data.mora.dias_bloqueo', 1)
            ->assertJsonPath('data.mora.escala.0.dias_sancion', 3)
            ->assertJsonPath('data.danos.dias_restriccion', 7)
            ->assertJsonPath('data.elegibilidad.requiere_capacitacion', false);
    }

    public function test_get_incluye_las_categorias_y_su_nivel_de_restriccion(): void
    {
        $categoria = Categoria::create(['nombre' => 'Portátil', 'nivel_restriccion' => 1]);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/configuracion')
            ->assertStatus(200)
            ->assertJsonPath('data.categorias.0.id', $categoria->id)
            ->assertJsonPath('data.categorias.0.nombre', 'Portátil')
            ->assertJsonPath('data.categorias.0.nivel_restriccion', 1);
    }

    public function test_put_acepta_correos_permitidos_para_el_registro(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/configuracion', [
                'registro.correos_permitidos' => ['@ecci.edu.co', 'docente@otra-universidad.edu.co'],
            ])
            ->assertOk()
            ->assertJsonPath('data.registro.correos_permitidos.0', '@ecci.edu.co')
            ->assertJsonPath('data.registro.correos_permitidos.1', 'docente@otra-universidad.edu.co');
    }

    public function test_put_rechaza_un_correo_permitido_invalido(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/configuracion', [
                'registro.correos_permitidos' => ['no-es-un-correo'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parametros.registro.correos_permitidos']);
    }

    public function test_put_actualiza_los_parametros_y_get_los_refleja(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/configuracion', [
                'cupos.roles' => ['usuario' => 1, 'encargado' => 2, 'admin' => 4],
                'mora.dias_bloqueo' => 2,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.cupos.roles.usuario', 1)
            ->assertJsonPath('data.mora.dias_bloqueo', 2);

        $this->assertDatabaseHas('configuraciones', ['clave' => 'mora.dias_bloqueo', 'valor' => '2']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/configuracion')
            ->assertStatus(200)
            ->assertJsonPath('data.cupos.roles.encargado', 2)
            ->assertJsonPath('data.mora.dias_bloqueo', 2);
    }

    public function test_put_tambien_acepta_el_mapa_envuelto_en_parametros(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/configuracion', [
                'parametros' => ['danos.dias_restriccion' => 4],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.danos.dias_restriccion', 4);

        $this->assertSame(4, (int) Configuracion::where('clave', 'danos.dias_restriccion')->value(
            'valor'
        ));
    }

    public function test_put_rechaza_una_clave_desconocida(): void
    {
        $response = $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/configuracion', ['cupos.inventado' => 5]);

        $response->assertStatus(422)->assertJsonValidationErrors(['parametros.cupos.inventado']);
    }

    public function test_put_rechaza_un_valor_de_tipo_incorrecto(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/configuracion', ['mora.dias_bloqueo' => 'muchos'])
            ->assertStatus(422);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/configuracion', ['elegibilidad.requiere_capacitacion' => 'quizá'])
            ->assertStatus(422);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/configuracion', ['mora.escala' => 'no-es-lista'])
            ->assertStatus(422);
    }

    public function test_put_rechaza_un_cuerpo_vacio(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/configuracion', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parametros']);
    }

    public function test_la_configuracion_es_exclusiva_del_admin(): void
    {
        $encargado = User::factory()->create();
        $encargado->assignRole('encargado');

        $this->actingAs($encargado, 'sanctum')
            ->getJson('/api/configuracion')
            ->assertStatus(403);

        $this->actingAs($encargado, 'sanctum')
            ->putJson('/api/configuracion', ['mora.dias_bloqueo' => 2])
            ->assertStatus(403);

        $usuario = User::factory()->create();
        $usuario->assignRole('usuario');

        $this->actingAs($usuario, 'sanctum')
            ->getJson('/api/configuracion')
            ->assertStatus(403);
    }

    public function test_el_admin_cambia_el_nivel_de_restriccion_de_una_categoria(): void
    {
        $categoria = Categoria::create(['nombre' => 'Equipos especializados']);

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson("/api/categorias/{$categoria->id}/restriccion", ['nivel_restriccion' => 2])
            ->assertStatus(200)
            ->assertJsonPath('data.nivel_restriccion', 2);

        $this->assertSame(2, $categoria->fresh()->nivel_restriccion);
    }

    public function test_la_restriccion_de_categoria_valida_el_nivel_y_el_rol(): void
    {
        $categoria = Categoria::create(['nombre' => 'Equipos de laboratorio']);
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/categorias/{$categoria->id}/restriccion", ['nivel_restriccion' => 3])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nivel_restriccion']);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/categorias/{$categoria->id}/restriccion", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nivel_restriccion']);

        $encargado = User::factory()->create();
        $encargado->assignRole('encargado');

        $this->actingAs($encargado, 'sanctum')
            ->patchJson("/api/categorias/{$categoria->id}/restriccion", ['nivel_restriccion' => 1])
            ->assertStatus(403);
    }
}

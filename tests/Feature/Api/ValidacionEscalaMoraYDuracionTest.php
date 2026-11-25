<?php

namespace Tests\Feature\Api;

use App\Models\Categoria;
use App\Models\User;
use App\Services\Parametros;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG-0007 y BUG-0008 — validación de los parámetros de configuración.
 *
 * - mora.escala: tramos contiguos, sin solapes, con «hasta» >= «desde», y
 *   solo el último puede quedar abierto. Un tramo con 0 días de sanción es la
 *   forma correcta de expresar un periodo de gracia.
 * - duracion.categorias: la categoría debe existir y la duración ser >= 1.
 */
class ValidacionEscalaMoraYDuracionTest extends TestCase
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

    /**
     * @param  array<int, array{desde: int, hasta: int|null, dias_sancion: int}>  $escala
     */
    private function guardarEscala(array $escala)
    {
        return $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/configuracion', ['mora.escala' => $escala]);
    }

    public function test_rechaza_un_tramo_con_hasta_menor_que_desde(): void
    {
        $this->guardarEscala([
            ['desde' => 5, 'hasta' => 3, 'dias_sancion' => 5],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parametros.mora.escala']);
    }

    public function test_rechaza_una_escala_con_huecos(): void
    {
        $response = $this->guardarEscala([
            ['desde' => 1, 'hasta' => 2, 'dias_sancion' => 3],
            ['desde' => 6, 'hasta' => null, 'dias_sancion' => 15],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['parametros.mora.escala']);

        $this->assertStringContainsString('hueco', $response->json('errors')['parametros.mora.escala'][0]);
    }

    public function test_rechaza_una_escala_con_tramos_solapados(): void
    {
        $this->guardarEscala([
            ['desde' => 1, 'hasta' => 3, 'dias_sancion' => 3],
            ['desde' => 2, 'hasta' => 5, 'dias_sancion' => 7],
            ['desde' => 6, 'hasta' => null, 'dias_sancion' => 15],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parametros.mora.escala']);
    }

    public function test_rechaza_mas_de_un_tramo_abierto(): void
    {
        $this->guardarEscala([
            ['desde' => 1, 'hasta' => null, 'dias_sancion' => 3],
            ['desde' => 2, 'hasta' => null, 'dias_sancion' => 7],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parametros.mora.escala']);
    }

    public function test_rechaza_una_escala_que_no_empieza_en_un_dia(): void
    {
        $this->guardarEscala([
            ['desde' => 2, 'hasta' => null, 'dias_sancion' => 3],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parametros.mora.escala']);
    }

    public function test_rechaza_una_escala_cuya_ultima_cobertura_es_cerrada(): void
    {
        $response = $this->guardarEscala([
            ['desde' => 1, 'hasta' => 2, 'dias_sancion' => 3],
            ['desde' => 3, 'hasta' => 5, 'dias_sancion' => 7],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['parametros.mora.escala']);

        $this->assertStringContainsString('abierto', $response->json('errors')['parametros.mora.escala'][0]);
    }

    public function test_acepta_una_escala_contigua_aunque_llegue_desordenada(): void
    {
        $this->guardarEscala([
            ['desde' => 6, 'hasta' => null, 'dias_sancion' => 15],
            ['desde' => 1, 'hasta' => 2, 'dias_sancion' => 3],
            ['desde' => 3, 'hasta' => 5, 'dias_sancion' => 7],
        ])
            ->assertOk()
            ->assertJsonCount(3, 'data.mora.escala');

        $parametros = app(Parametros::class);

        $this->assertSame(3, $parametros->diasSancionPorRetraso(1));
        $this->assertSame(7, $parametros->diasSancionPorRetraso(4));
        $this->assertSame(15, $parametros->diasSancionPorRetraso(30));
    }

    public function test_acepta_un_periodo_de_gracia_con_cero_dias_de_sancion(): void
    {
        $this->guardarEscala([
            ['desde' => 1, 'hasta' => 2, 'dias_sancion' => 0],
            ['desde' => 3, 'hasta' => null, 'dias_sancion' => 7],
        ])
            ->assertOk();

        $this->assertSame(0, app(Parametros::class)->diasSancionPorRetraso(2));
        $this->assertSame(7, app(Parametros::class)->diasSancionPorRetraso(3));
    }

    public function test_rechaza_la_duracion_de_una_categoria_inexistente(): void
    {
        $response = $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/configuracion', [
                'duracion.categorias' => ['999999' => 7],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['parametros.duracion.categorias']);

        $this->assertStringContainsString('no existe', $response->json('errors')['parametros.duracion.categorias'][0]);
    }

    public function test_rechaza_una_duracion_de_categoria_igual_a_cero(): void
    {
        $categoria = Categoria::create(['nombre' => 'Categoría sin duración']);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/configuracion', [
                'duracion.categorias' => [(string) $categoria->id => 0],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parametros.duracion.categorias']);
    }

    public function test_acepta_una_duracion_de_un_dia_para_una_categoria_existente(): void
    {
        $categoria = Categoria::create(['nombre' => 'Categoría corta']);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/configuracion', [
                'duracion.categorias' => [(string) $categoria->id => 1],
            ])
            ->assertOk()
            ->assertJsonPath("data.duracion.categorias.{$categoria->id}", 1);
    }
}

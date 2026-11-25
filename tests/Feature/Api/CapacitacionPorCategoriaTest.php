<?php

namespace Tests\Feature\Api;

use App\Enums\CondicionEquipo;
use App\Enums\EstadoPrestamo;
use App\Models\Categoria;
use App\Models\Equipo;
use App\Models\Prestamo;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * RN-01 — capacitación por categoría de equipo.
 *
 * Pedido del PO: «si estás capacitado para un router no te da la
 * capacitación para un servidor». Cada categoría decide si la exige
 * (categorias.requiere_capacitacion) y el usuario se capacita en cada una
 * por separado (tabla capacitaciones).
 */
class CapacitacionPorCategoriaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function usuario(string $rol, Categoria ...$capacitadoEn): User
    {
        $user = User::factory()->capacitadoEn(...$capacitadoEn)->create();
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

    private function solicitar(User $usuario, Equipo $equipo): TestResponse
    {
        return $this->actingAs($usuario, 'sanctum')->postJson('/api/prestamos', [
            'equipo_id' => $equipo->id,
            'motivo' => 'Práctica de redes',
            'fecha_inicio' => now()->addHour()->format('Y-m-d H:i:s'),
            'fecha_devolucion_estimada' => now()->addDays(2)->format('Y-m-d H:i:s'),
        ]);
    }

    private function mensaje(Categoria $categoria): string
    {
        return "Debes estar capacitado en «{$categoria->nombre}» para solicitar este equipo.";
    }

    // ---------------------------------------------------------------------
    // Elegibilidad al solicitar
    // ---------------------------------------------------------------------

    public function test_una_categoria_sin_requisito_la_solicita_cualquiera(): void
    {
        $portatiles = Categoria::factory()->create();

        $this->solicitar($this->usuario('estudiante'), $this->equipo($portatiles))
            ->assertCreated();
    }

    public function test_con_requisito_y_capacitado_en_esa_categoria_puede_solicitar(): void
    {
        $routers = Categoria::factory()->requiereCapacitacion()->create(['nombre' => 'Routers']);

        $this->solicitar($this->usuario('estudiante', $routers), $this->equipo($routers))
            ->assertCreated();
    }

    public function test_capacitado_en_otra_categoria_no_puede_solicitar(): void
    {
        $routers = Categoria::factory()->requiereCapacitacion()->create(['nombre' => 'Routers']);
        $servidores = Categoria::factory()->requiereCapacitacion()->create(['nombre' => 'Servidores']);
        $usuario = $this->usuario('estudiante', $routers);

        $this->solicitar($usuario, $this->equipo($servidores))
            ->assertUnprocessable()
            ->assertJsonPath('errors.usuario_id.0', 'Debes estar capacitado en «Servidores» para solicitar este equipo.');

        $this->assertDatabaseCount('prestamos', 0);
    }

    public function test_el_personal_administrativo_tambien_necesita_la_capacitacion(): void
    {
        $servidores = Categoria::factory()->requiereCapacitacion()->create(['nombre' => 'Servidores']);

        foreach (['admin', 'encargado'] as $rol) {
            $this->solicitar($this->usuario($rol), $this->equipo($servidores))
                ->assertUnprocessable()
                ->assertJsonPath('errors.usuario_id.0', $this->mensaje($servidores));
        }
    }

    public function test_quitar_el_requisito_de_la_categoria_desbloquea_la_solicitud(): void
    {
        $servidores = Categoria::factory()->requiereCapacitacion()->create(['nombre' => 'Servidores']);
        $usuario = $this->usuario('estudiante');
        $equipo = $this->equipo($servidores);

        $this->solicitar($usuario, $equipo)->assertUnprocessable();

        $this->actingAs($this->usuario('admin'), 'sanctum')
            ->patchJson("/api/categorias/{$servidores->id}/restriccion", ['requiere_capacitacion' => false])
            ->assertOk()
            ->assertJsonPath('data.requiere_capacitacion', false);

        $this->solicitar($usuario, $equipo)->assertCreated();
    }

    public function test_otorgar_la_capacitacion_por_la_api_desbloquea_la_solicitud(): void
    {
        $servidores = Categoria::factory()->requiereCapacitacion()->create();
        $usuario = $this->usuario('estudiante');
        $equipo = $this->equipo($servidores);

        $this->solicitar($usuario, $equipo)->assertUnprocessable();

        $this->actingAs($this->usuario('admin'), 'sanctum')
            ->putJson("/api/usuarios/{$usuario->id}", ['capacitaciones' => [$servidores->id]])
            ->assertOk();

        $this->solicitar($usuario, $equipo)->assertCreated();
    }

    // ---------------------------------------------------------------------
    // BUG-0010: la entrega revalida la elegibilidad
    // ---------------------------------------------------------------------

    public function test_revocar_la_capacitacion_despues_de_aprobar_frena_la_entrega(): void
    {
        $routers = Categoria::factory()->requiereCapacitacion()->create(['nombre' => 'Routers']);
        $solicitante = $this->usuario('estudiante', $routers);
        $equipo = $this->equipo($routers);
        $encargado = $this->usuario('encargado');

        $prestamoId = $this->solicitar($solicitante, $equipo)->assertCreated()->json('data.id');

        $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamoId}/aprobacion")
            ->assertOk()
            ->assertJsonPath('data.estado', EstadoPrestamo::Aprobado->value);

        $this->actingAs($this->usuario('admin'), 'sanctum')
            ->putJson("/api/usuarios/{$solicitante->id}", ['capacitaciones' => []])
            ->assertOk();

        $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamoId}/entrega", ['condicion_entrega' => CondicionEquipo::Bueno->value])
            ->assertUnprocessable()
            ->assertJsonPath('errors.usuario_id.0', 'No se puede entregar el equipo: '.$this->mensaje($routers));

        $this->assertSame(EstadoPrestamo::Aprobado, Prestamo::find($prestamoId)->estado);
        $this->assertSame(Equipo::ESTADO_DISPONIBLE, $equipo->fresh()->estado);
    }

    public function test_con_la_capacitacion_vigente_la_entrega_procede(): void
    {
        $routers = Categoria::factory()->requiereCapacitacion()->create();
        $solicitante = $this->usuario('estudiante', $routers);
        $equipo = $this->equipo($routers);
        $prestamo = Prestamo::factory()->aprobado()->create([
            'usuario_id' => $solicitante->id,
            'equipo_id' => $equipo->id,
        ]);

        $this->actingAs($this->usuario('encargado'), 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/entrega", ['condicion_entrega' => CondicionEquipo::Bueno->value])
            ->assertOk()
            ->assertJsonPath('data.estado', EstadoPrestamo::Entregado->value);
    }

    // ---------------------------------------------------------------------
    // PATCH /api/categorias/{categoria}/restriccion
    // ---------------------------------------------------------------------

    public function test_el_admin_activa_el_requisito_sin_tocar_el_nivel(): void
    {
        $categoria = Categoria::factory()->create(['nivel_restriccion' => Categoria::REQUIERE_AUTORIZACION]);

        $this->actingAs($this->usuario('admin'), 'sanctum')
            ->patchJson("/api/categorias/{$categoria->id}/restriccion", ['requiere_capacitacion' => true])
            ->assertOk()
            ->assertJsonPath('data.requiere_capacitacion', true)
            ->assertJsonPath('data.nivel_restriccion', Categoria::REQUIERE_AUTORIZACION);

        $categoria->refresh();
        $this->assertTrue($categoria->requiere_capacitacion);
        $this->assertSame(Categoria::REQUIERE_AUTORIZACION, $categoria->nivel_restriccion);
    }

    public function test_el_admin_cambia_nivel_y_requisito_a_la_vez(): void
    {
        $categoria = Categoria::factory()->create();

        $this->actingAs($this->usuario('admin'), 'sanctum')
            ->patchJson("/api/categorias/{$categoria->id}/restriccion", [
                'nivel_restriccion' => Categoria::SOLO_PERSONAL,
                'requiere_capacitacion' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.nivel_restriccion', Categoria::SOLO_PERSONAL)
            ->assertJsonPath('data.requiere_capacitacion', true);
    }

    public function test_cambiar_solo_el_nivel_conserva_el_requisito(): void
    {
        $categoria = Categoria::factory()->requiereCapacitacion()->create();

        $this->actingAs($this->usuario('admin'), 'sanctum')
            ->patchJson("/api/categorias/{$categoria->id}/restriccion", ['nivel_restriccion' => Categoria::LIBRE])
            ->assertOk()
            ->assertJsonPath('data.requiere_capacitacion', true);
    }

    public function test_el_requisito_debe_ser_booleano(): void
    {
        $categoria = Categoria::factory()->create();
        $admin = $this->usuario('admin');

        foreach (['quizá', null, 2] as $valor) {
            $this->actingAs($admin, 'sanctum')
                ->patchJson("/api/categorias/{$categoria->id}/restriccion", ['requiere_capacitacion' => $valor])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['requiere_capacitacion']);
        }

        $this->assertFalse($categoria->fresh()->requiere_capacitacion);
    }

    public function test_sin_ningun_campo_responde_422(): void
    {
        $categoria = Categoria::factory()->create();

        $this->actingAs($this->usuario('admin'), 'sanctum')
            ->patchJson("/api/categorias/{$categoria->id}/restriccion", ['otro' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'nivel_restriccion' => 'Debes enviar el nivel de restricción o si la categoría requiere capacitación.',
            ]);
    }

    public function test_solo_el_admin_cambia_el_requisito_de_la_categoria(): void
    {
        $categoria = Categoria::factory()->create();

        foreach (['encargado', 'estudiante'] as $rol) {
            $this->actingAs($this->usuario($rol), 'sanctum')
                ->patchJson("/api/categorias/{$categoria->id}/restriccion", ['requiere_capacitacion' => true])
                ->assertForbidden();
        }

        $this->assertFalse($categoria->fresh()->requiere_capacitacion);
    }

    public function test_la_configuracion_expone_el_requisito_de_cada_categoria(): void
    {
        $libre = Categoria::factory()->create();
        $exigente = Categoria::factory()->requiereCapacitacion()->create();

        $this->actingAs($this->usuario('admin'), 'sanctum')
            ->getJson('/api/configuracion')
            ->assertOk()
            ->assertJsonPath('data.categorias.0.id', $libre->id)
            ->assertJsonPath('data.categorias.0.requiere_capacitacion', false)
            ->assertJsonPath('data.categorias.1.id', $exigente->id)
            ->assertJsonPath('data.categorias.1.requiere_capacitacion', true);
    }

    public function test_el_parametro_global_retirado_ya_no_se_acepta(): void
    {
        $this->actingAs($this->usuario('admin'), 'sanctum')
            ->putJson('/api/configuracion', ['elegibilidad.requiere_capacitacion' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'parametros.elegibilidad.requiere_capacitacion' => 'El parámetro «elegibilidad.requiere_capacitacion» no existe.',
            ]);

        $this->assertDatabaseMissing('configuraciones', ['clave' => 'elegibilidad.requiere_capacitacion']);
    }

    // ---------------------------------------------------------------------
    // Catálogo: requiere_capacitacion y usuario_capacitado
    // ---------------------------------------------------------------------

    public function test_el_catalogo_indica_si_el_usuario_puede_pedir_cada_equipo(): void
    {
        $libre = Categoria::factory()->create(['nombre' => 'A Portátiles']);
        $routers = Categoria::factory()->requiereCapacitacion()->create(['nombre' => 'B Routers']);
        $servidores = Categoria::factory()->requiereCapacitacion()->create(['nombre' => 'C Servidores']);
        $usuario = $this->usuario('estudiante', $routers);

        $porCategoria = [
            $libre->id => $this->equipo($libre)->id,
            $routers->id => $this->equipo($routers)->id,
            $servidores->id => $this->equipo($servidores)->id,
        ];

        $equipos = collect(
            $this->actingAs($usuario, 'sanctum')->getJson('/api/equipos')->assertOk()->json('data')
        )->keyBy('id');

        $this->assertFalse($equipos[$porCategoria[$libre->id]]['categoria']['requiere_capacitacion']);
        $this->assertTrue($equipos[$porCategoria[$libre->id]]['usuario_capacitado']);

        $this->assertTrue($equipos[$porCategoria[$routers->id]]['categoria']['requiere_capacitacion']);
        $this->assertTrue($equipos[$porCategoria[$routers->id]]['usuario_capacitado']);

        $this->assertTrue($equipos[$porCategoria[$servidores->id]]['categoria']['requiere_capacitacion']);
        $this->assertFalse($equipos[$porCategoria[$servidores->id]]['usuario_capacitado']);
    }

    public function test_el_detalle_del_equipo_refleja_la_capacitacion_del_usuario(): void
    {
        $servidores = Categoria::factory()->requiereCapacitacion()->create();
        $equipo = $this->equipo($servidores);

        $this->actingAs($this->usuario('estudiante'), 'sanctum')
            ->getJson("/api/equipos/{$equipo->id}")
            ->assertOk()
            ->assertJsonPath('data.categoria.requiere_capacitacion', true)
            ->assertJsonPath('data.usuario_capacitado', false);

        $this->actingAs($this->usuario('estudiante', $servidores), 'sanctum')
            ->getJson("/api/equipos/{$equipo->id}")
            ->assertOk()
            ->assertJsonPath('data.usuario_capacitado', true);
    }

    public function test_el_catalogo_consulta_las_capacitaciones_una_sola_vez(): void
    {
        $routers = Categoria::factory()->requiereCapacitacion()->create();
        $servidores = Categoria::factory()->requiereCapacitacion()->create();
        $usuario = $this->usuario('estudiante', $routers);

        $consultas = function () use ($usuario): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($usuario, 'sanctum')->getJson('/api/equipos')->assertOk();
            $total = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $total;
        };

        $this->equipo($routers);
        // Calentamiento: la primera petición carga además los roles del usuario.
        $consultas();
        $conUno = $consultas();

        foreach ([$routers, $servidores, $servidores, $routers] as $categoria) {
            $this->equipo($categoria);
        }

        // count + equipos + categorías + capacitaciones del usuario.
        $this->assertSame(4, $conUno);
        $this->assertSame($conUno, $consultas());
    }
}

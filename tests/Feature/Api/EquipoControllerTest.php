<?php

namespace Tests\Feature\Api;

use App\Models\Categoria;
use App\Models\Equipo;
use App\Models\User;
use Database\Seeders\CategoriaSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EquipoControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(CategoriaSeeder::class);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Sanctum::actingAs($admin);

        return $admin;
    }

    private function actingAsEncargado(): User
    {
        $encargado = User::factory()->create();
        $encargado->assignRole('encargado');

        Sanctum::actingAs($encargado);

        return $encargado;
    }

    private function actingAsUsuario(): User
    {
        $usuario = User::factory()->create();
        $usuario->assignRole('usuario');

        Sanctum::actingAs($usuario);

        return $usuario;
    }

    // --- Autorizacion ---

    public function test_peticion_sin_autenticar_no_puede_listar_equipos(): void
    {
        $this->getJson('/api/equipos')->assertStatus(401);
    }

    public function test_cualquier_usuario_autenticado_puede_listar_equipos(): void
    {
        $this->actingAsUsuario();
        Equipo::factory()->create();

        $this->getJson('/api/equipos')->assertOk();
    }

    public function test_peticion_sin_autenticar_no_puede_consultar_historial(): void
    {
        $equipo = Equipo::factory()->create();

        $this->getJson("/api/equipos/{$equipo->id}/historial")->assertStatus(401);
    }

    public function test_cualquier_usuario_autenticado_puede_consultar_historial(): void
    {
        $this->actingAsUsuario();
        $equipo = Equipo::factory()->create();

        $this->getJson("/api/equipos/{$equipo->id}/historial")->assertOk();
    }

    public function test_per_page_invalido_se_normaliza_en_vez_de_fallar(): void
    {
        $this->actingAsUsuario();

        // Negativo y cero: sin max(1, ...), llegaban a paginate() como tal
        // (500 en MySQL con LIMIT negativo, o meta.last_page = 0 con cero).
        $this->getJson('/api/equipos?per_page=-5')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1);

        $this->getJson('/api/equipos?per_page=0')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1);

        // Mayor a 100: min(..., 100) acota el tamano maximo de pagina a 100.
        $this->getJson('/api/equipos?per_page=150')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_usuario_sin_rol_admin_no_puede_registrar_equipos(): void
    {
        $this->actingAsUsuario();

        $this->postJson('/api/equipos', $this->datosValidos())
            ->assertStatus(403);
    }

    // --- Registrar ---

    public function test_admin_puede_registrar_un_equipo(): void
    {
        $this->actingAsAdmin();
        $categoria = Categoria::first();

        $response = $this->postJson('/api/equipos', [
            'codigo' => 'EQ-001',
            'nombre' => 'Portátil Dell 14"',
            'categoria_id' => $categoria->id,
            'descripcion' => 'Core i5, 8GB RAM',
            'observaciones' => 'Con cargador original',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.codigo', 'EQ-001')
            ->assertJsonPath('data.estado', 'disponible')
            ->assertJsonPath('data.categoria.id', $categoria->id);

        $this->assertDatabaseHas('equipos', ['codigo' => 'EQ-001', 'estado' => 'disponible']);
    }

    public function test_registrar_equipo_ignora_el_estado_enviado_por_el_cliente(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/equipos', [
            ...$this->datosValidos(),
            'estado' => 'mantenimiento',
        ]);

        // El sistema asigna el estado inicial, no lo elige quien registra.
        $response->assertCreated()->assertJsonPath('data.estado', 'disponible');
    }

    public function test_registrar_equipo_rechaza_codigo_duplicado(): void
    {
        $this->actingAsAdmin();
        Equipo::factory()->create(['codigo' => 'EQ-DUP']);

        $this->postJson('/api/equipos', [
            ...$this->datosValidos(),
            'codigo' => 'EQ-DUP',
        ])->assertStatus(422)->assertJsonValidationErrors(['codigo']);
    }

    public function test_registrar_equipo_exige_categoria_existente(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/equipos', [
            ...$this->datosValidos(),
            'categoria_id' => 999,
        ])->assertStatus(422)->assertJsonValidationErrors(['categoria_id']);
    }

    public function test_registrar_equipo_exige_codigo_y_nombre(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/equipos', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['codigo', 'nombre', 'categoria_id']);
    }

    // --- Consultar ---

    public function test_equipo_registrado_aparece_en_el_catalogo(): void
    {
        $this->actingAsAdmin();
        $this->postJson('/api/equipos', $this->datosValidos())->assertCreated();

        $this->getJson('/api/equipos')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.codigo', 'EQ-100');
    }

    public function test_puede_consultar_un_equipo_puntual(): void
    {
        $this->actingAsUsuario();
        $equipo = Equipo::factory()->create();

        $this->getJson("/api/equipos/{$equipo->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $equipo->id);
    }

    // --- Cambiar estado (HU-04) ---

    public function test_admin_puede_cambiar_el_estado_de_un_equipo(): void
    {
        $admin = $this->actingAsAdmin();
        $equipo = Equipo::factory()->create(); // estado inicial: disponible

        $this->patchJson("/api/equipos/{$equipo->id}/estado", ['estado' => 'mantenimiento'])
            ->assertOk()
            ->assertJsonPath('data.estado', 'mantenimiento');

        $this->assertDatabaseHas('historial_estados', [
            'equipo_id' => $equipo->id,
            'estado_anterior' => 'disponible',
            'estado_nuevo' => 'mantenimiento',
            'user_id' => $admin->id,
        ]);
    }

    public function test_cambio_de_estado_genera_exactamente_un_registro_de_historial(): void
    {
        $this->actingAsAdmin();
        $equipo = Equipo::factory()->create();

        // 1 registro generado por la creacion inicial (observer 'created')
        $this->assertDatabaseCount('historial_estados', 1);

        $this->patchJson("/api/equipos/{$equipo->id}/estado", ['estado' => 'mantenimiento'])
            ->assertOk();

        // Exactamente un registro adicional por la transicion (observer 'updated')
        $this->assertDatabaseCount('historial_estados', 2);
        $this->assertSame(2, $equipo->historialEstados()->count());
    }

    public function test_usuario_sin_rol_admin_no_puede_cambiar_estado(): void
    {
        $this->actingAsUsuario();
        $equipo = Equipo::factory()->create();

        $this->patchJson("/api/equipos/{$equipo->id}/estado", ['estado' => 'mantenimiento'])
            ->assertStatus(403);
    }

    public function test_cambiar_estado_exige_un_valor_valido(): void
    {
        $this->actingAsAdmin();
        $equipo = Equipo::factory()->create();

        $this->patchJson("/api/equipos/{$equipo->id}/estado", ['estado' => 'perdido'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['estado']);
    }

    public function test_equipo_dado_de_baja_no_puede_volver_a_cambiar_de_estado(): void
    {
        $this->actingAsAdmin();
        $equipo = Equipo::factory()->create(['estado' => 'dado_de_baja']);
        $conteoInicialHistorial = $equipo->historialEstados()->count();

        $this->patchJson("/api/equipos/{$equipo->id}/estado", ['estado' => 'disponible'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['estado'])
            ->assertJsonPath('errors.estado.0', 'Un equipo dado de baja no puede cambiar de estado.');

        $this->assertSame('dado_de_baja', $equipo->fresh()->estado);
        $this->assertSame($conteoInicialHistorial, $equipo->historialEstados()->count());
    }

    public function test_registrar_equipo_crea_la_entrada_inicial_del_historial(): void
    {
        $admin = $this->actingAsAdmin();

        $response = $this->postJson('/api/equipos', $this->datosValidos());
        $equipoId = $response->json('data.id');

        $this->assertDatabaseHas('historial_estados', [
            'equipo_id' => $equipoId,
            'estado_anterior' => null,
            'estado_nuevo' => 'disponible',
            'user_id' => $admin->id,
        ]);
    }

    public function test_puede_consultar_el_historial_de_un_equipo(): void
    {
        $this->actingAsAdmin();
        $equipo = Equipo::factory()->create();

        $this->patchJson("/api/equipos/{$equipo->id}/estado", ['estado' => 'mantenimiento']);
        $this->patchJson("/api/equipos/{$equipo->id}/estado", ['estado' => 'disponible']);

        $response = $this->getJson("/api/equipos/{$equipo->id}/historial")->assertOk();

        // Estructura data + meta (sin links, consistente con index de equipos y usuarios)
        $response->assertJsonStructure([
            'data' => [
                '*' => ['id', 'estado_anterior', 'estado_nuevo', 'usuario', 'fecha'],
            ],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);

        // Mas reciente primero: disponible (vuelta) -> mantenimiento -> creacion.
        $response->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.estado_anterior', 'mantenimiento')
            ->assertJsonPath('data.0.estado_nuevo', 'disponible')
            ->assertJsonPath('data.1.estado_nuevo', 'mantenimiento')
            ->assertJsonPath('data.2.estado_anterior', null)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 1);
    }

    public function test_historial_respeta_parametro_per_page(): void
    {
        $this->actingAsAdmin();
        $equipo = Equipo::factory()->create();

        $this->patchJson("/api/equipos/{$equipo->id}/estado", ['estado' => 'mantenimiento']);
        $this->patchJson("/api/equipos/{$equipo->id}/estado", ['estado' => 'disponible']);

        // Con per_page=2: primera pagina con 2 registros y 2 paginas en total
        $response = $this->getJson("/api/equipos/{$equipo->id}/historial?per_page=2")
            ->assertOk();

        $response->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_per_page_de_historial_se_normaliza_ante_valores_fuera_de_rango(): void
    {
        $this->actingAsUsuario();
        $equipo = Equipo::factory()->create();

        $this->getJson("/api/equipos/{$equipo->id}/historial?per_page=150")
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);

        $this->getJson("/api/equipos/{$equipo->id}/historial?per_page=-5")
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1);

        $this->getJson("/api/equipos/{$equipo->id}/historial?per_page=0")
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1);
    }

    public function test_equipo_sin_historial_devuelve_data_vacio(): void
    {
        $this->actingAsUsuario();
        $equipo = Equipo::factory()->create();
        $equipo->historialEstados()->delete();

        $this->getJson("/api/equipos/{$equipo->id}/historial")
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.current_page', 1);
    }

    public function test_historial_no_tiene_usuario_cuando_el_equipo_se_crea_sin_admin_autenticado(): void
    {
        $equipo = Equipo::factory()->create();

        $this->assertDatabaseHas('historial_estados', [
            'equipo_id' => $equipo->id,
            'user_id' => null,
        ]);
    }

    public function test_peticion_sin_autenticar_no_puede_consultar_detalle_de_equipo(): void
    {
        $equipo = Equipo::factory()->create();

        $this->getJson("/api/equipos/{$equipo->id}")->assertStatus(401);
    }

    public function test_equipo_inexistente_devuelve_404(): void
    {
        $this->actingAsUsuario();
        $this->getJson('/api/equipos/999999')
            ->assertStatus(404)
            ->assertJsonPath('message', 'No query results for model [App\Models\Equipo] 999999');
    }

    // --- Disponibilidad en catalogo (HU-05) ---

    public function test_usuario_con_rol_usuario_lista_catalogo_con_estructura_de_disponibilidad(): void
    {
        $this->actingAsUsuario();
        Equipo::factory()->create();

        $response = $this->getJson('/api/equipos');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'codigo',
                        'nombre',
                        'categoria' => ['id', 'nombre'],
                        'descripcion',
                        'disponible',
                        'puede_solicitarse',
                        'estado_disponibilidad',
                        'estado',
                        'observaciones',
                    ],
                ],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    }

    public function test_equipo_disponible_muestra_atributos_de_disponibilidad_positivos(): void
    {
        $this->actingAsUsuario();
        Equipo::factory()->create(['estado' => Equipo::ESTADO_DISPONIBLE]);

        $this->getJson('/api/equipos')
            ->assertOk()
            ->assertJsonPath('data.0.disponible', true)
            ->assertJsonPath('data.0.puede_solicitarse', true)
            ->assertJsonPath('data.0.estado_disponibilidad', 'disponible')
            ->assertJsonPath('data.0.estado', 'disponible');
    }

    public function test_equipo_en_prestamo_aparece_en_catalogo_como_no_disponible(): void
    {
        $this->actingAsUsuario();
        Equipo::factory()->create(['estado' => 'en_prestamo']);

        $this->getJson('/api/equipos')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.disponible', false)
            ->assertJsonPath('data.0.puede_solicitarse', false)
            ->assertJsonPath('data.0.estado_disponibilidad', 'no_disponible')
            ->assertJsonPath('data.0.estado', 'en_prestamo');
    }

    public function test_equipo_en_mantenimiento_aparece_en_catalogo_como_no_disponible(): void
    {
        $this->actingAsUsuario();
        Equipo::factory()->create(['estado' => 'mantenimiento']);

        $this->getJson('/api/equipos')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.disponible', false)
            ->assertJsonPath('data.0.puede_solicitarse', false)
            ->assertJsonPath('data.0.estado_disponibilidad', 'no_disponible')
            ->assertJsonPath('data.0.estado', 'mantenimiento');
    }

    public function test_equipo_dado_de_baja_no_es_visible_para_rol_usuario_y_su_detalle_da_404(): void
    {
        $this->actingAsUsuario();
        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_DADO_DE_BAJA]);

        $this->getJson('/api/equipos')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson("/api/equipos/{$equipo->id}")
            ->assertStatus(404)
            ->assertJsonPath('message', "No query results for model [App\Models\Equipo] {$equipo->id}");
    }

    public function test_usuario_autenticado_sin_ningun_rol_no_ve_dado_de_baja_y_detalle_da_404(): void
    {
        $usuarioSinRol = User::factory()->create();
        Sanctum::actingAs($usuarioSinRol);

        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_DADO_DE_BAJA]);

        $this->getJson('/api/equipos')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson("/api/equipos/{$equipo->id}")
            ->assertStatus(404)
            ->assertJsonPath('message', "No query results for model [App\Models\Equipo] {$equipo->id}");
    }

    public function test_404_de_equipo_dado_de_baja_y_de_id_inexistente_devuelven_el_mismo_json(): void
    {
        $this->actingAsUsuario();
        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_DADO_DE_BAJA]);
        $id = $equipo->id;

        // 404 cuando el equipo existe pero esta dado de baja (oculto a usuarios comunes)
        $responseDadoDeBaja = $this->getJson("/api/equipos/{$id}");
        $responseDadoDeBaja->assertStatus(404);

        // Eliminamos el equipo de la base de datos para simular que no existe
        $equipo->delete();

        // 404 cuando el equipo realmente no existe en la base de datos (route model binding)
        $responseInexistente = $this->getJson("/api/equipos/{$id}");
        $responseInexistente->assertStatus(404);

        // Ambas respuestas deben ser exactamente identicas en status, cabeceras de error y cuerpo JSON
        $this->assertSame($responseInexistente->json(), $responseDadoDeBaja->json());
        $this->assertSame("No query results for model [App\Models\Equipo] {$id}", $responseDadoDeBaja->json('message'));
    }

    public function test_equipo_dado_de_baja_es_visible_para_admin_y_encargado(): void
    {
        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_DADO_DE_BAJA]);

        // Rol admin puede ver en catalogo y en detalle
        $this->actingAsAdmin();
        $this->getJson('/api/equipos')
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->getJson("/api/equipos/{$equipo->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $equipo->id);

        // Rol encargado puede ver en catalogo y en detalle
        $this->actingAsEncargado();
        $this->getJson('/api/equipos')
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->getJson("/api/equipos/{$equipo->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $equipo->id);
    }

    public function test_detalle_de_equipo_incluye_campos_de_disponibilidad_y_categoria(): void
    {
        $this->actingAsUsuario();
        $equipo = Equipo::factory()->create(['estado' => 'disponible']);

        $this->getJson("/api/equipos/{$equipo->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $equipo->id)
            ->assertJsonPath('data.disponible', true)
            ->assertJsonPath('data.puede_solicitarse', true)
            ->assertJsonPath('data.estado_disponibilidad', 'disponible')
            ->assertJsonPath('data.categoria.id', $equipo->categoria_id);
    }

    public function test_listado_sin_problema_de_n_mas_uno_sobre_categoria(): void
    {
        $this->actingAsUsuario();
        $categorias = Categoria::factory()->count(3)->create();

        // Warm up del usuario y roles para que la autenticacion no afecte el conteo
        $this->getJson('/api/equipos');

        // 1 equipo: medimos queries
        Equipo::factory()->create(['categoria_id' => $categorias[0]->id]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/equipos')->assertOk();
        $queriesConUnEquipo = count(DB::getQueryLog());

        // Limpiamos y creamos 6 equipos con diferentes categorias
        Equipo::query()->delete();
        foreach ($categorias as $categoria) {
            Equipo::factory()->count(2)->create(['categoria_id' => $categoria->id]);
        }

        DB::flushQueryLog();
        $this->getJson('/api/equipos')->assertOk();
        $queriesConVariosEquipos = count(DB::getQueryLog());

        // El numero de queries debe mantenerse constante (1 count + 1 equipos + 1 categorias)
        $this->assertSame(3, $queriesConUnEquipo);
        $this->assertSame(3, $queriesConVariosEquipos);
    }

    /**
     * @return array<string, mixed>
     */
    private function datosValidos(): array
    {
        return [
            'codigo' => 'EQ-100',
            'nombre' => 'Tablet Samsung',
            'categoria_id' => Categoria::first()->id,
        ];
    }
}

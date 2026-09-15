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
            ->assertStatus(404);
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
            ->assertStatus(404);
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
            ->assertStatus(404);
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

        // Ambas respuestas deben ser exactamente identicas en status y cuerpo JSON
        $this->assertSame($responseInexistente->json(), $responseDadoDeBaja->json());
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

    // --- Filtros del catalogo (HU-05 Fase 2) ---

    public function test_filtro_categoria_id_filtra_correctamente(): void
    {
        $this->actingAsUsuario();
        $catLaptops = Categoria::factory()->create(['nombre' => 'Portátiles']);
        $catAudio = Categoria::factory()->create(['nombre' => 'Audio']);

        Equipo::factory()->create(['categoria_id' => $catLaptops->id, 'nombre' => 'Laptop Dell']);
        Equipo::factory()->create(['categoria_id' => $catLaptops->id, 'nombre' => 'Laptop Lenovo']);
        Equipo::factory()->create(['categoria_id' => $catAudio->id, 'nombre' => 'Micrófono Shure']);

        $response = $this->getJson("/api/equipos?categoria_id={$catLaptops->id}");

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.categoria.id', $catLaptops->id)
            ->assertJsonPath('data.1.categoria.id', $catLaptops->id);
    }

    public function test_filtro_disponible_true_y_uno_devuelven_solo_disponibles(): void
    {
        $this->actingAsUsuario();
        Equipo::factory()->create(['estado' => Equipo::ESTADO_DISPONIBLE]);
        Equipo::factory()->create(['estado' => 'en_prestamo']);
        Equipo::factory()->create(['estado' => 'mantenimiento']);

        // Con disponible=true
        $this->getJson('/api/equipos?disponible=true')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.disponible', true)
            ->assertJsonPath('data.0.estado', 'disponible');

        // Con disponible=1
        $this->getJson('/api/equipos?disponible=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.disponible', true)
            ->assertJsonPath('data.0.estado', 'disponible');
    }

    public function test_filtro_disponible_false_y_cero_devuelven_solo_no_disponibles_sin_dados_de_baja_para_usuario(): void
    {
        $this->actingAsUsuario();
        Equipo::factory()->create(['estado' => Equipo::ESTADO_DISPONIBLE]);
        Equipo::factory()->create(['estado' => 'en_prestamo']);
        Equipo::factory()->create(['estado' => 'mantenimiento']);
        Equipo::factory()->create(['estado' => Equipo::ESTADO_DADO_DE_BAJA]);

        // Con disponible=false
        $resFalse = $this->getJson('/api/equipos?disponible=false');
        $resFalse->assertOk()->assertJsonCount(2, 'data');
        foreach ($resFalse->json('data') as $item) {
            $this->assertFalse($item['disponible']);
            $this->assertNotSame('dado_de_baja', $item['estado']);
        }

        // Con disponible=0
        $resCero = $this->getJson('/api/equipos?disponible=0');
        $resCero->assertOk()->assertJsonCount(2, 'data');
        foreach ($resCero->json('data') as $item) {
            $this->assertFalse($item['disponible']);
            $this->assertNotSame('dado_de_baja', $item['estado']);
        }
    }

    public function test_filtro_disponible_con_valor_invalido_devuelve_422(): void
    {
        $this->actingAsUsuario();

        $this->getJson('/api/equipos?disponible=quizas')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['disponible']);
    }

    public function test_buscar_encuentra_por_nombre_codigo_y_descripcion(): void
    {
        $this->actingAsUsuario();
        $cat = Categoria::factory()->create(['nombre' => 'Portátiles']);

        Equipo::factory()->create([
            'categoria_id' => $cat->id,
            'nombre' => 'Portátil Lenovo ThinkPad',
            'codigo' => 'EQ-LAP-01',
            'descripcion' => '16GB RAM SSD',
        ]);
        Equipo::factory()->create([
            'categoria_id' => $cat->id,
            'nombre' => 'Micrófono Shure SM58',
            'codigo' => 'EQ-MIC-02',
            'descripcion' => 'Vocal dinámico cardioide',
        ]);
        Equipo::factory()->create([
            'categoria_id' => $cat->id,
            'nombre' => 'Tablet Apple iPad Air',
            'codigo' => 'EQ-TAB-03',
            'descripcion' => 'Pantalla Liquid Retina',
        ]);

        // Por nombre
        $this->getJson('/api/equipos?buscar=ThinkPad')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.codigo', 'EQ-LAP-01');

        // Por codigo
        $this->getJson('/api/equipos?buscar=MIC-02')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.codigo', 'EQ-MIC-02');

        // Por descripcion
        $this->getJson('/api/equipos?buscar=cardioide')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.codigo', 'EQ-MIC-02');
    }

    public function test_buscar_con_comodin_porcentaje_no_devuelve_todos_los_equipos(): void
    {
        $this->actingAsUsuario();
        Equipo::factory()->create(['nombre' => 'Portátil Dell', 'codigo' => 'EQ-DELL-01']);
        Equipo::factory()->create(['nombre' => 'Tablet Samsung', 'codigo' => 'EQ-TAB-01']);

        $this->getJson('/api/equipos?buscar=%25')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_buscar_de_mas_de_100_caracteres_devuelve_422(): void
    {
        $this->actingAsUsuario();

        $this->getJson('/api/equipos?buscar='.str_repeat('a', 101))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['buscar']);
    }

    public function test_combinacion_de_filtros_categoria_disponible_y_buscar(): void
    {
        $this->actingAsUsuario();
        $catLaptops = Categoria::factory()->create(['nombre' => 'Portátiles']);
        $catAudio = Categoria::factory()->create(['nombre' => 'Audio']);

        // Coincide con todo (categoria Laptops, disponible, buscar Dell)
        Equipo::factory()->create([
            'categoria_id' => $catLaptops->id,
            'nombre' => 'Laptop Dell Latitude',
            'codigo' => 'EQ-L01',
            'estado' => 'disponible',
        ]);
        // Misma categoria y disponible, pero otro nombre
        Equipo::factory()->create([
            'categoria_id' => $catLaptops->id,
            'nombre' => 'Laptop HP ProBook',
            'codigo' => 'EQ-L02',
            'estado' => 'disponible',
        ]);
        // Misma categoria y coincide busqueda, pero NO disponible
        Equipo::factory()->create([
            'categoria_id' => $catLaptops->id,
            'nombre' => 'Laptop Dell XPS',
            'codigo' => 'EQ-L03',
            'estado' => 'en_prestamo',
        ]);
        // Coincide busqueda y disponible, pero OTRA categoria
        Equipo::factory()->create([
            'categoria_id' => $catAudio->id,
            'nombre' => 'Micrófono Dell Voice',
            'codigo' => 'EQ-A01',
            'estado' => 'disponible',
        ]);

        $this->getJson("/api/equipos?categoria_id={$catLaptops->id}&disponible=true&buscar=Dell")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.codigo', 'EQ-L01');
    }

    public function test_cada_valor_de_ordenar_produce_el_orden_esperado(): void
    {
        $this->actingAsUsuario();
        $cat = Categoria::factory()->create(['nombre' => 'Portátiles']);

        // Creamos tres equipos con nombres y estados controlados y marcas de tiempo distintas
        $eqA = Equipo::factory()->create([
            'categoria_id' => $cat->id,
            'nombre' => 'A-Portátil Acer',
            'estado' => 'en_prestamo',
            'created_at' => now()->subMinutes(10),
        ]);
        $eqB = Equipo::factory()->create([
            'categoria_id' => $cat->id,
            'nombre' => 'B-Portátil Dell',
            'estado' => 'disponible',
            'created_at' => now()->subMinutes(5),
        ]);
        $eqC = Equipo::factory()->create([
            'categoria_id' => $cat->id,
            'nombre' => 'C-Portátil HP',
            'estado' => 'disponible',
            'created_at' => now(),
        ]);

        // 1. ordenar=nombre (alfabetico asc: A, B, C)
        $resNombre = $this->getJson('/api/equipos?ordenar=nombre')->assertOk();
        $this->assertSame([$eqA->id, $eqB->id, $eqC->id], array_column($resNombre->json('data'), 'id'));

        // 2. ordenar=-nombre (alfabetico desc: C, B, A)
        $resDesc = $this->getJson('/api/equipos?ordenar=-nombre')->assertOk();
        $this->assertSame([$eqC->id, $eqB->id, $eqA->id], array_column($resDesc->json('data'), 'id'));

        // 3. ordenar=disponibles_primero (disponibles B y C al inicio ordenados por nombre, luego no disponible A)
        $resDisp = $this->getJson('/api/equipos?ordenar=disponibles_primero')->assertOk();
        $this->assertSame([$eqB->id, $eqC->id, $eqA->id], array_column($resDisp->json('data'), 'id'));

        // 4. ordenar=recientes (created_at desc: C, B, A)
        $resRecientes = $this->getJson('/api/equipos?ordenar=recientes')->assertOk();
        $this->assertSame([$eqC->id, $eqB->id, $eqA->id], array_column($resRecientes->json('data'), 'id'));
    }

    public function test_ordenar_con_valor_invalido_devuelve_422(): void
    {
        $this->actingAsUsuario();

        $this->getJson('/api/equipos?ordenar=precio')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ordenar']);
    }

    public function test_categoria_id_inexistente_devuelve_422(): void
    {
        $this->actingAsUsuario();

        $this->getJson('/api/equipos?categoria_id=999999')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['categoria_id']);
    }

    // --- Tests de ajuste FASE 2.1 ---

    public function test_buscar_con_caracter_porcentaje_escapado_filtra_literalmente(): void
    {
        $this->actingAsUsuario();
        $cat = Categoria::factory()->create(['nombre' => 'Baterías']);

        $eq1 = Equipo::factory()->create([
            'categoria_id' => $cat->id,
            'nombre' => 'Equipo Batería A',
            'codigo' => 'EQ-BAT-01',
            'descripcion' => 'Batería 100% nueva',
        ]);
        Equipo::factory()->create([
            'categoria_id' => $cat->id,
            'nombre' => 'Equipo Batería B',
            'codigo' => 'EQ-BAT-02',
            'descripcion' => 'Batería 1000 mAh',
        ]);

        $this->getJson('/api/equipos?buscar=100%25')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $eq1->id);
    }

    public function test_buscar_con_guion_bajo_escapado_filtra_literalmente(): void
    {
        $this->actingAsUsuario();
        $cat = Categoria::factory()->create(['nombre' => 'General']);

        $eq1 = Equipo::factory()->create([
            'categoria_id' => $cat->id,
            'nombre' => 'Equipo Especial 1',
            'codigo' => 'EQ_A1',
            'descripcion' => 'Prueba guion bajo',
        ]);
        Equipo::factory()->create([
            'categoria_id' => $cat->id,
            'nombre' => 'Equipo Especial 2',
            'codigo' => 'EQA01',
            'descripcion' => 'Prueba sin guion bajo',
        ]);

        $this->getJson('/api/equipos?buscar=EQ_A')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $eq1->id);
    }

    public function test_buscar_con_signo_exclamacion_no_rompe_la_consulta(): void
    {
        $this->actingAsUsuario();
        Equipo::factory()->create(['nombre' => 'Portátil Dell', 'codigo' => 'EQ-DELL-01']);

        $this->getJson('/api/equipos?buscar=!')
            ->assertOk();
    }

    public function test_parametros_vacios_no_filtran_el_catalogo(): void
    {
        $this->actingAsUsuario();
        $cat1 = Categoria::factory()->create(['nombre' => 'Portátiles']);
        $cat2 = Categoria::factory()->create(['nombre' => 'Tablets']);

        Equipo::factory()->create([
            'categoria_id' => $cat1->id,
            'nombre' => 'Portátil Dell',
            'estado' => 'disponible',
        ]);
        Equipo::factory()->create([
            'categoria_id' => $cat2->id,
            'nombre' => 'Tablet Lenovo',
            'estado' => 'en_prestamo',
        ]);

        // Sin parametros: devuelve ambos equipos
        $resBase = $this->getJson('/api/equipos')->assertOk()->assertJsonCount(2, 'data');

        // Con disponible= vacio: devuelve disponibles y no disponibles (igual que sin parametro)
        $resDisponibleVacio = $this->getJson('/api/equipos?disponible=')->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame($resBase->json('data'), $resDisponibleVacio->json('data'));

        // Con categoria_id= y buscar= vacios: tampoco filtran
        $this->getJson('/api/equipos?categoria_id=')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/equipos?buscar=')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/equipos?ordenar=')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_orden_estable_con_desempate_por_id_en_paginacion(): void
    {
        $this->actingAsUsuario();
        $cat = Categoria::factory()->create(['nombre' => 'Portátiles']);

        // 3 equipos con el mismo nombre
        $eq1 = Equipo::factory()->create(['categoria_id' => $cat->id, 'nombre' => 'Portátil Dell']);
        $eq2 = Equipo::factory()->create(['categoria_id' => $cat->id, 'nombre' => 'Portátil Dell']);
        $eq3 = Equipo::factory()->create(['categoria_id' => $cat->id, 'nombre' => 'Portátil Dell']);

        // Pagina 1 (per_page=2)
        $page1 = $this->getJson('/api/equipos?per_page=2&page=1')->assertOk();
        $idsPage1 = array_column($page1->json('data'), 'id');
        $this->assertCount(2, $idsPage1);

        // Pagina 2 (per_page=2)
        $page2 = $this->getJson('/api/equipos?per_page=2&page=2')->assertOk();
        $idsPage2 = array_column($page2->json('data'), 'id');
        $this->assertCount(1, $idsPage2);

        // Las dos paginas juntas contienen los 3 ids sin repetir
        $todosLosIds = array_merge($idsPage1, $idsPage2);
        $this->assertCount(3, array_unique($todosLosIds));
        $this->assertEqualsCanonicalizing([$eq1->id, $eq2->id, $eq3->id], $todosLosIds);
    }

    public function test_mensajes_de_error_de_validacion_estan_en_espanol(): void
    {
        $this->actingAsUsuario();

        $response = $this->getJson('/api/equipos?categoria_id=999999&disponible=quizas');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['categoria_id', 'disponible']);

        $message = $response->json('message');
        $this->assertStringNotContainsString('The selected', $message);
        $this->assertStringNotContainsString('more errors', $message);
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

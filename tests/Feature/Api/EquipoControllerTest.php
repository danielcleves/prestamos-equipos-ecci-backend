<?php

namespace Tests\Feature\Api;

use App\Models\Categoria;
use App\Models\Equipo;
use App\Models\User;
use Database\Seeders\CategoriaSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->patchJson("/api/equipos/{$equipo->id}/estado", ['estado' => 'disponible'])
            ->assertStatus(422);

        $this->assertSame('dado_de_baja', $equipo->fresh()->estado);
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

        // Mas reciente primero: disponible (vuelta) -> mantenimiento -> creacion.
        $response->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.estado_anterior', 'mantenimiento')
            ->assertJsonPath('data.0.estado_nuevo', 'disponible')
            ->assertJsonPath('data.1.estado_nuevo', 'mantenimiento')
            ->assertJsonPath('data.2.estado_anterior', null);
    }

    public function test_historial_no_tiene_usuario_cuando_el_equipo_se_crea_sin_admin_autenticado(): void
    {
        $equipo = Equipo::factory()->create();

        $this->assertDatabaseHas('historial_estados', [
            'equipo_id' => $equipo->id,
            'user_id' => null,
        ]);
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

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

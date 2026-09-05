<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Sanctum::actingAs($admin);

        return $admin;
    }

    // --- Autorizacion ---

    public function test_usuario_sin_rol_admin_no_puede_listar_usuarios(): void
    {
        $usuario = User::factory()->create();
        $usuario->assignRole('encargado');
        Sanctum::actingAs($usuario);

        $this->getJson('/api/usuarios')->assertStatus(403);
    }

    public function test_peticion_sin_autenticar_no_puede_listar_usuarios(): void
    {
        $this->getJson('/api/usuarios')->assertStatus(401);
    }

    // --- Listar y consultar ---

    public function test_admin_puede_listar_usuarios(): void
    {
        $this->actingAsAdmin();
        User::factory()->count(2)->create();

        $this->getJson('/api/usuarios')
            ->assertOk()
            ->assertJsonCount(3, 'data') // el admin + los 2 creados
            ->assertJsonStructure([
                'data' => [['id', 'name', 'email', 'is_active', 'roles']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ])
            ->assertJsonPath('meta.total', 3);
    }

    public function test_listado_de_usuarios_esta_paginado(): void
    {
        $this->actingAsAdmin();
        User::factory()->count(19)->create(); // + admin = 20

        $primeraPagina = $this->getJson('/api/usuarios')->assertOk();
        $primeraPagina->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.total', 20)
            ->assertJsonPath('meta.last_page', 2);

        $this->getJson('/api/usuarios?per_page=5')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.last_page', 4);
    }

    public function test_admin_puede_consultar_un_usuario(): void
    {
        $this->actingAsAdmin();
        $usuario = User::factory()->create();

        $this->getJson("/api/usuarios/{$usuario->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $usuario->id);
    }

    // --- Crear ---

    public function test_admin_puede_registrar_un_usuario_con_rol(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/usuarios', [
            'name' => 'Nuevo Encargado',
            'email' => 'encargado@ecci.edu.co',
            'password' => 'password123',
            'role' => 'encargado',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.email', 'encargado@ecci.edu.co')
            ->assertJsonPath('data.roles', ['encargado'])
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('users', ['email' => 'encargado@ecci.edu.co']);
    }

    public function test_registrar_usuario_exige_rol_valido(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/usuarios', [
            'name' => 'Test',
            'email' => 'test-rol@ecci.edu.co',
            'password' => 'password123',
            'role' => 'rol-inventado',
        ])->assertStatus(422)->assertJsonValidationErrors(['role']);
    }

    public function test_registrar_usuario_rechaza_correo_duplicado(): void
    {
        $admin = $this->actingAsAdmin();

        $this->postJson('/api/usuarios', [
            'name' => 'Duplicado',
            'email' => $admin->email,
            'password' => 'password123',
            'role' => 'usuario',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    // --- Actualizar ---

    public function test_admin_puede_modificar_informacion_del_usuario(): void
    {
        $this->actingAsAdmin();
        $usuario = User::factory()->create(['name' => 'Nombre Viejo']);

        $this->putJson("/api/usuarios/{$usuario->id}", [
            'name' => 'Nombre Nuevo',
        ])->assertOk()->assertJsonPath('data.name', 'Nombre Nuevo');

        $this->assertSame('Nombre Nuevo', $usuario->fresh()->name);
    }

    public function test_admin_puede_cambiar_el_rol_de_un_usuario(): void
    {
        $this->actingAsAdmin();
        $usuario = User::factory()->create();
        $usuario->assignRole('usuario');

        $this->putJson("/api/usuarios/{$usuario->id}", [
            'role' => 'encargado',
        ])->assertOk()->assertJsonPath('data.roles', ['encargado']);

        // syncRoles reemplaza, no se acumula con el rol anterior.
        $this->assertSame(['encargado'], $usuario->fresh()->getRoleNames()->all());
    }

    // --- Activar / desactivar ---

    public function test_admin_puede_desactivar_y_reactivar_un_usuario(): void
    {
        $this->actingAsAdmin();
        $usuario = User::factory()->create();

        $this->patchJson("/api/usuarios/{$usuario->id}/desactivar")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
        $this->assertFalse($usuario->fresh()->is_active);

        $this->patchJson("/api/usuarios/{$usuario->id}/activar")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
        $this->assertTrue($usuario->fresh()->is_active);
    }

    public function test_admin_no_puede_desactivarse_a_si_mismo(): void
    {
        $admin = $this->actingAsAdmin();

        $this->patchJson("/api/usuarios/{$admin->id}/desactivar")
            ->assertStatus(422);

        $this->assertTrue($admin->fresh()->is_active);
    }
}

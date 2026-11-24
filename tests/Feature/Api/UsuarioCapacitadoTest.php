<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RN-01 — el estado de capacitación se administra desde el CRUD de usuarios.
 */
class UsuarioCapacitadoTest extends TestCase
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

    public function test_put_usuarios_actualiza_capacitado(): void
    {
        $usuario = User::factory()->create();
        $usuario->assignRole('usuario');

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/usuarios/{$usuario->id}", ['capacitado' => false])
            ->assertStatus(200)
            ->assertJsonPath('data.capacitado', false);

        $this->assertFalse($usuario->fresh()->capacitado);
    }

    public function test_post_usuarios_crea_capacitado_por_defecto_y_permite_desmarcarlo(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/usuarios', [
                'name' => 'Sin capacitación',
                'email' => 'sin.capacitacion@ecci.edu.co',
                'password' => 'password123',
                'role' => 'usuario',
                'capacitado' => false,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.capacitado', false);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/usuarios', [
                'name' => 'Con capacitación',
                'email' => 'con.capacitacion@ecci.edu.co',
                'password' => 'password123',
                'role' => 'usuario',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.capacitado', true);
    }

    public function test_capacitado_debe_ser_booleano(): void
    {
        $usuario = User::factory()->create();
        $usuario->assignRole('usuario');

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/usuarios/{$usuario->id}", ['capacitado' => 'tal vez'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['capacitado']);
    }
}

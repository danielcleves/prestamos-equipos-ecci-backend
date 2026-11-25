<?php

namespace Tests\Feature\Api;

use App\Models\Equipo;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG-0005 — una cuenta desactivada no puede conservar la sesión.
 *
 * Al desactivar se revocan todos los tokens del usuario y, como segunda
 * barrera, el middleware 'activo' rechaza con 401 cualquier petición
 * autenticada de una cuenta inactiva aunque el token siguiera existiendo.
 */
class CuentaDesactivadaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function usuario(string $rol): User
    {
        $user = User::factory()->create();
        $user->assignRole($rol);

        return $user;
    }

    private function token(User $usuario): string
    {
        return $usuario->createToken('pruebas')->plainTextToken;
    }

    private function equipo(): Equipo
    {
        return Equipo::factory()->create(['estado' => Equipo::ESTADO_DISPONIBLE]);
    }

    /**
     * @return array<string, string>
     */
    private function payload(Equipo $equipo): array
    {
        return [
            'equipo_id' => (string) $equipo->id,
            'motivo' => 'Práctica de laboratorio',
            'fecha_inicio' => now()->addHour()->format('Y-m-d H:i:s'),
            'fecha_devolucion_estimada' => now()->addDays(2)->format('Y-m-d H:i:s'),
        ];
    }

    public function test_desactivar_revoca_los_tokens_y_la_cuenta_ya_no_puede_solicitar(): void
    {
        $estudiante = $this->usuario('estudiante');
        $tokenEstudiante = $this->token($estudiante);

        $admin = $this->usuario('admin');
        $tokenAdmin = $this->token($admin);

        $equipo = $this->equipo();

        $this->withHeader('Authorization', "Bearer {$tokenAdmin}")
            ->patchJson("/api/usuarios/{$estudiante->id}/desactivar")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertSame(0, $estudiante->tokens()->count());

        // Dentro de una prueba el guard conserva el usuario ya resuelto; en
        // producción cada petición parte de cero.
        $this->app['auth']->forgetGuards();

        // El token ya no existe, así que la autenticación falla de entrada
        // (el mensaje de cuenta desactivada lo cubre la prueba del token que
        // sobrevive, que sí llega al middleware).
        $this->withHeader('Authorization', "Bearer {$tokenEstudiante}")
            ->postJson('/api/prestamos', $this->payload($equipo))
            ->assertStatus(401)
            ->assertJsonPath('message', 'No autenticado.');

        $this->assertDatabaseCount('prestamos', 0);
    }

    public function test_un_token_que_sobrevive_es_revocado_y_rechazado_por_el_middleware(): void
    {
        $estudiante = $this->usuario('estudiante');
        $token = $this->token($estudiante);

        // Desactivación directa en la base (por ejemplo, una corrección manual):
        // el token queda vivo a propósito para comprobar la segunda barrera.
        User::whereKey($estudiante->id)->update(['is_active' => false]);
        $this->assertSame(1, $estudiante->tokens()->count());

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/prestamos')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Tu cuenta está desactivada. Contacta a un administrador.');

        $this->assertSame(0, $estudiante->tokens()->count());
    }

    public function test_un_admin_desactivado_no_puede_crear_usuarios(): void
    {
        $adminPrincipal = $this->usuario('admin');
        $tokenPrincipal = $this->token($adminPrincipal);

        $adminDesactivado = $this->usuario('admin');
        $tokenDesactivado = $this->token($adminDesactivado);

        $this->withHeader('Authorization', "Bearer {$tokenPrincipal}")
            ->patchJson("/api/usuarios/{$adminDesactivado->id}/desactivar")
            ->assertOk();

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$tokenDesactivado}")
            ->postJson('/api/usuarios', [
                'name' => 'Admin intruso',
                'email' => 'intruso@ecci.edu.co',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'role' => 'admin',
            ])
            ->assertStatus(401);

        $this->assertDatabaseMissing('users', ['email' => 'intruso@ecci.edu.co']);
        $this->assertSame(2, User::role('admin')->count());
    }

    public function test_un_usuario_reactivado_puede_volver_a_entrar_y_solicitar(): void
    {
        $estudiante = User::factory()->create(['email' => 'reactivado@ecci.edu.co']);
        $estudiante->assignRole('estudiante');
        $equipo = $this->equipo();

        $admin = $this->usuario('admin');
        $tokenAdmin = $this->token($admin);

        $this->withHeader('Authorization', "Bearer {$tokenAdmin}")
            ->patchJson("/api/usuarios/{$estudiante->id}/desactivar")
            ->assertOk();

        $this->postJson('/api/login', [
            'email' => 'reactivado@ecci.edu.co',
            'password' => 'password',
        ])->assertStatus(422);

        $this->withHeader('Authorization', "Bearer {$tokenAdmin}")
            ->patchJson("/api/usuarios/{$estudiante->id}/activar")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $login = $this->postJson('/api/login', [
            'email' => 'reactivado@ecci.edu.co',
            'password' => 'password',
        ])->assertOk();

        $tokenNuevo = $login->json('token');
        $this->assertNotEmpty($tokenNuevo);

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$tokenNuevo}")
            ->getJson('/api/me')
            ->assertOk();

        $this->withHeader('Authorization', "Bearer {$tokenNuevo}")
            ->postJson('/api/prestamos', $this->payload($equipo))
            ->assertStatus(201);
    }
}

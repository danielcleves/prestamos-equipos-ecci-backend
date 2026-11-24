<?php

namespace Tests\Feature\Api;

use App\Models\Configuracion;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Auto-registro de solicitantes con lista de correos/dominios permitidos.
 */
class RegistroTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    /**
     * @return array<string, string>
     */
    private function payload(string $email): array
    {
        return [
            'name' => 'Estudiante Nuevo',
            'email' => $email,
            'password' => 'secreta123',
            'password_confirmation' => 'secreta123',
        ];
    }

    public function test_un_correo_institucional_crea_un_solicitante(): void
    {
        $this->postJson('/api/register', $this->payload('nuevo@ecci.edu.co'))
            ->assertCreated()
            ->assertJsonPath('user.email', 'nuevo@ecci.edu.co');

        $user = User::where('email', 'nuevo@ecci.edu.co')->firstOrFail();

        $this->assertTrue($user->is_active);
        $this->assertTrue($user->capacitado);
        $this->assertTrue($user->hasRole('usuario'));
    }

    public function test_un_correo_exacto_configurado_tambien_puede_registrarse(): void
    {
        Configuracion::establecer('registro.correos_permitidos', [
            '@ecci.edu.co',
            'docente@otra-universidad.edu.co',
        ]);

        $this->postJson('/api/register', $this->payload('docente@otra-universidad.edu.co'))
            ->assertCreated();
    }

    public function test_un_correo_no_autorizado_se_rechaza(): void
    {
        $this->postJson('/api/register', $this->payload('intruso@gmail.com'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseMissing('users', ['email' => 'intruso@gmail.com']);
    }

    public function test_no_se_permite_registrar_un_correo_duplicado(): void
    {
        User::factory()->create(['email' => 'repetido@ecci.edu.co']);

        $this->postJson('/api/register', $this->payload('repetido@ecci.edu.co'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_el_rol_siempre_es_solicitante_aunque_se_envie_otro(): void
    {
        $datos = $this->payload('admin.falso@ecci.edu.co');
        $datos['role'] = 'admin';

        $this->postJson('/api/register', $datos)->assertCreated();

        $user = User::where('email', 'admin.falso@ecci.edu.co')->firstOrFail();

        $this->assertTrue($user->hasRole('usuario'));
        $this->assertFalse($user->hasRole('admin'));
    }

    public function test_la_contrasena_debe_confirmarse(): void
    {
        $datos = $this->payload('sin.confirmar@ecci.edu.co');
        unset($datos['password_confirmation']);

        $this->postJson('/api/register', $datos)
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }
}

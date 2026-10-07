<?php

namespace Tests\Unit;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_es_personal_administrativo_devuelve_true_para_admin_y_encargado(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $encargado = User::factory()->create();
        $encargado->assignRole('encargado');

        $this->assertTrue($admin->esPersonalAdministrativo());
        $this->assertTrue($encargado->esPersonalAdministrativo());
    }

    public function test_es_personal_administrativo_devuelve_false_para_usuario_y_usuario_sin_rol(): void
    {
        $usuario = User::factory()->create();
        $usuario->assignRole('usuario');

        $sinRol = User::factory()->create();

        $this->assertFalse($usuario->esPersonalAdministrativo());
        $this->assertFalse($sinRol->esPersonalAdministrativo());
    }
}

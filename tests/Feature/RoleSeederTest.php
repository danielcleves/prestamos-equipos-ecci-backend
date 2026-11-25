<?php

namespace Tests\Feature;

use App\Models\Rol;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_crea_los_tres_roles_iniciales(): void
    {
        $this->seed(RoleSeeder::class);

        $this->assertSame(
            ['admin', 'encargado', 'estudiante'],
            Role::pluck('name')->sort()->values()->all(),
        );
    }

    public function test_correr_el_seeder_dos_veces_no_duplica_roles(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->assertSame(3, Role::count());
    }

    public function test_estudiante_es_el_rol_predeterminado_y_cada_rol_trae_su_cupo(): void
    {
        $this->seed(RoleSeeder::class);

        $this->assertSame('estudiante', Rol::predeterminado()?->name);
        $this->assertSame(
            ['admin' => 10, 'encargado' => 5, 'estudiante' => 3],
            Rol::mapaCupos()->sortKeys()->all(),
        );
    }

    public function test_el_seeder_no_sobreescribe_un_cupo_ajustado_por_el_admin(): void
    {
        $this->seed(RoleSeeder::class);
        Rol::where('name', 'estudiante')->update(['cupo_prestamos' => 8, 'etiqueta' => 'Alumno']);

        $this->seed(RoleSeeder::class);

        $this->assertDatabaseHas('roles', ['name' => 'estudiante', 'cupo_prestamos' => 8, 'etiqueta' => 'Alumno']);
    }
}

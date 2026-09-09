<?php

namespace Tests\Feature;

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_crea_los_tres_roles_oficiales(): void
    {
        $this->seed(RoleSeeder::class);

        $this->assertSame(
            ['admin', 'encargado', 'usuario'],
            Role::pluck('name')->sort()->values()->all(),
        );
    }

    public function test_correr_el_seeder_dos_veces_no_duplica_roles(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->assertSame(3, Role::count());
    }
}

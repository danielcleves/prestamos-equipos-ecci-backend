<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Roles oficiales del proyecto (decision cerrada, ver CONTEXTO-PROYECTO.md
     * seccion 3). Los permisos de cada uno se definen aparte, cuando el
     * equipo valide la matriz de la seccion 5 — este seeder solo garantiza
     * que los roles existan.
     */
    private const ROLES = ['admin', 'encargado', 'usuario'];

    public function run(): void
    {
        foreach (self::ROLES as $role) {
            Role::firstOrCreate(['name' => $role]);
        }
    }
}

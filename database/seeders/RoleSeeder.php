<?php

namespace Database\Seeders;

use App\Models\Rol;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Roles iniciales del proyecto.
     *
     * - admin y encargado son roles de sistema (permisos de personal).
     * - estudiante es el rol de solicitante predeterminado (auto-registro).
     *
     * El admin puede crear más roles de solicitante (profesor, coordinador…)
     * desde /api/roles, cada uno con su cupo (RN-02).
     *
     * firstOrCreate: nunca sobreescribe los cupos ni las etiquetas que el admin
     * haya ajustado.
     */
    private const ROLES = [
        'admin' => ['etiqueta' => 'Administrador', 'cupo_prestamos' => 10, 'es_predeterminado' => false],
        'encargado' => ['etiqueta' => 'Personal de préstamo', 'cupo_prestamos' => 5, 'es_predeterminado' => false],
        'estudiante' => ['etiqueta' => 'Estudiante', 'cupo_prestamos' => 3, 'es_predeterminado' => true],
    ];

    public function run(): void
    {
        foreach (self::ROLES as $nombre => $atributos) {
            Rol::firstOrCreate(['name' => $nombre, 'guard_name' => 'web'], $atributos);
        }
    }
}

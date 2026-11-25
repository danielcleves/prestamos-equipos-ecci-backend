<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * RN-02 (rediseño): roles personalizados con su propio cupo.
     *
     * - Los roles de Spatie pasan a tener etiqueta, descripción, cupo de
     *   préstamos vivos y una marca de «predeterminado» (el que recibe el
     *   auto-registro).
     * - El rol genérico «usuario» se renombra a «estudiante» (predeterminado).
     *   model_has_roles referencia el id, así que los usuarios lo conservan.
     * - El cupo deja de vivir en configuraciones.cupos.roles: se copia a la
     *   columna roles.cupo_prestamos y la fila se elimina.
     *
     * Solo actualiza filas existentes; en una base recién migrada los roles los
     * crea RoleSeeder con sus valores iniciales.
     */
    public function up(): void
    {
        $tabla = config('permission.table_names.roles', 'roles');

        Schema::table($tabla, function (Blueprint $table) {
            $table->string('etiqueta', 60)->nullable()->after('name');
            $table->string('descripcion')->nullable()->after('etiqueta');
            $table->unsignedSmallInteger('cupo_prestamos')->default(3)->after('descripcion');
            $table->boolean('es_predeterminado')->default(false)->after('cupo_prestamos');
        });

        $existeEstudiante = DB::table($tabla)->where('name', 'estudiante')->exists();
        if (! $existeEstudiante) {
            DB::table($tabla)->where('name', 'usuario')->update(['name' => 'estudiante']);
        }

        $cupos = $this->leerJson('cupos.roles');
        if (isset($cupos['usuario']) && ! isset($cupos['estudiante'])) {
            $cupos['estudiante'] = $cupos['usuario'];
        }

        $iniciales = [
            'admin' => ['etiqueta' => 'Administrador', 'cupo' => 10, 'predeterminado' => false],
            'encargado' => ['etiqueta' => 'Personal de préstamo', 'cupo' => 5, 'predeterminado' => false],
            'estudiante' => ['etiqueta' => 'Estudiante', 'cupo' => 3, 'predeterminado' => true],
        ];

        foreach ($iniciales as $nombre => $datos) {
            DB::table($tabla)->where('name', $nombre)->update([
                'etiqueta' => $datos['etiqueta'],
                'cupo_prestamos' => max(0, (int) ($cupos[$nombre] ?? $datos['cupo'])),
                'es_predeterminado' => $datos['predeterminado'],
            ]);
        }

        if (Schema::hasTable('configuraciones')) {
            DB::table('configuraciones')->where('clave', 'cupos.roles')->delete();

            $extra = $this->leerJson('duracion.roles_extra');
            if (isset($extra['usuario']) && ! isset($extra['estudiante'])) {
                $extra['estudiante'] = $extra['usuario'];
                unset($extra['usuario']);
                DB::table('configuraciones')->where('clave', 'duracion.roles_extra')
                    ->update(['valor' => json_encode($extra, JSON_UNESCAPED_UNICODE)]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $tabla = config('permission.table_names.roles', 'roles');

        $cupos = DB::table($tabla)->pluck('cupo_prestamos', 'name')->all();

        if (! DB::table($tabla)->where('name', 'usuario')->exists()) {
            DB::table($tabla)->where('name', 'estudiante')->update(['name' => 'usuario']);
        }

        if (Schema::hasTable('configuraciones') && $cupos !== []) {
            if (isset($cupos['estudiante'])) {
                $cupos['usuario'] = $cupos['estudiante'];
                unset($cupos['estudiante']);
            }

            DB::table('configuraciones')->updateOrInsert(
                ['clave' => 'cupos.roles'],
                [
                    'valor' => json_encode($cupos, JSON_UNESCAPED_UNICODE),
                    'tipo' => 'json',
                    'grupo' => 'cupos',
                    'descripcion' => 'Máximo de préstamos vivos por rol.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            $extra = $this->leerJson('duracion.roles_extra');
            if (isset($extra['estudiante'])) {
                $extra['usuario'] = $extra['estudiante'];
                unset($extra['estudiante']);
                DB::table('configuraciones')->where('clave', 'duracion.roles_extra')
                    ->update(['valor' => json_encode($extra, JSON_UNESCAPED_UNICODE)]);
            }
        }

        Schema::table($tabla, function (Blueprint $table) {
            $table->dropColumn(['etiqueta', 'descripcion', 'cupo_prestamos', 'es_predeterminado']);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return array<string, mixed>
     */
    private function leerJson(string $clave): array
    {
        if (! Schema::hasTable('configuraciones')) {
            return [];
        }

        $valor = DB::table('configuraciones')->where('clave', $clave)->value('valor');
        $decodificado = is_string($valor) ? json_decode($valor, true) : null;

        return is_array($decodificado) ? $decodificado : [];
    }
};

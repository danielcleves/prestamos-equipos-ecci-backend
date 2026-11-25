<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Parametros;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * RN-02 (rediseño) — Migración 2026_11_24_000008_add_perfil_to_roles_table.
 *
 * Simula una base desplegada antes del cambio (rol «usuario» y el cupo en
 * configuraciones.cupos.roles), aplica la migración y verifica que se
 * conservan asignaciones y cupos; después prueba el down y el ida y vuelta.
 *
 * Usa DatabaseMigrations (no RefreshDatabase) porque el DDL de MySQL hace
 * commit implícito y rompería la transacción de RefreshDatabase.
 *
 * Casos TC-ROL-050 … TC-ROL-054.
 */
class MigracionRolesTest extends TestCase
{
    use DatabaseMigrations;

    private const MIGRACION = '2026_11_24_000008_add_perfil_to_roles_table';

    protected function setUp(): void
    {
        parent::setUp();

        // Base en el estado anterior a la migración 000008.
        $this->deshacerHastaAntesDe000008();
        $this->assertFalse(Schema::hasColumn('roles', 'cupo_prestamos'));
    }

    /**
     * Deshace la migración 000008 y todas las posteriores (p. ej. la
     * capacitación por categoría), que se vuelven a aplicar con migrar().
     */
    private function deshacerHastaAntesDe000008(): void
    {
        $pasos = DB::table('migrations')->where('migration', '>=', self::MIGRACION)->count();
        $this->artisan('migrate:rollback', ['--step' => $pasos])->assertExitCode(0);
    }

    private function migrar(): void
    {
        $this->artisan('migrate')->assertExitCode(0);
        $this->assertTrue(DB::table('migrations')->where('migration', self::MIGRACION)->exists());
    }

    /**
     * Datos «de producción» previos: los tres roles antiguos, un usuario con
     * rol «usuario» y los parámetros guardados con la clave antigua.
     *
     * @return array{usuario: User, rol_id: int}
     */
    private function datosAntiguos(bool $conConfiguracion = true): array
    {
        $ids = [];
        foreach (['admin', 'encargado', 'usuario'] as $nombre) {
            $ids[$nombre] = DB::table('roles')->insertGetId([
                'name' => $nombre,
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $usuario = User::factory()->create();
        DB::table('model_has_roles')->insert([
            'role_id' => $ids['usuario'],
            'model_type' => User::class,
            'model_id' => $usuario->id,
        ]);

        if ($conConfiguracion) {
            $this->configuracion('cupos.roles', 'cupos', ['usuario' => 4, 'encargado' => 6, 'admin' => 12]);
            $this->configuracion('duracion.roles_extra', 'duracion', ['usuario' => 1, 'encargado' => 2, 'admin' => 3]);
        }

        return ['usuario' => $usuario, 'rol_id' => $ids['usuario']];
    }

    private function configuracion(string $clave, string $grupo, array $valor): void
    {
        DB::table('configuraciones')->insert([
            'clave' => $clave,
            'valor' => json_encode($valor),
            'tipo' => 'json',
            'grupo' => $grupo,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function parametroJson(string $clave): ?array
    {
        $valor = DB::table('configuraciones')->where('clave', $clave)->value('valor');

        return $valor === null ? null : json_decode($valor, true);
    }

    /** TC-ROL-050 / RN-02: «usuario» pasa a «estudiante» con el mismo id y el usuario lo conserva. */
    public function test_tc_rol_050_la_migracion_renombra_usuario_a_estudiante_y_conserva_las_asignaciones(): void
    {
        ['usuario' => $usuario, 'rol_id' => $rolId] = $this->datosAntiguos();

        $this->migrar();

        $this->assertDatabaseHas('roles', ['id' => $rolId, 'name' => 'estudiante', 'etiqueta' => 'Estudiante', 'es_predeterminado' => true]);
        $this->assertDatabaseMissing('roles', ['name' => 'usuario']);
        $this->assertSame(['estudiante'], $usuario->fresh()->getRoleNames()->all());
        $this->assertSame('estudiante', $usuario->fresh()->rolPrincipal());
        $this->assertSame(1, DB::table('roles')->where('es_predeterminado', true)->count());
    }

    /** TC-ROL-051 / RN-02: los cupos guardados se copian a roles.cupo_prestamos y la fila se borra. */
    public function test_tc_rol_051_la_migracion_copia_los_cupos_guardados_y_elimina_la_clave(): void
    {
        $this->datosAntiguos();

        $this->migrar();

        $this->assertSame(
            ['admin' => 12, 'encargado' => 6, 'estudiante' => 4],
            DB::table('roles')->orderBy('name')->pluck('cupo_prestamos', 'name')->map(fn ($c) => (int) $c)->all()
        );
        $this->assertDatabaseMissing('configuraciones', ['clave' => 'cupos.roles']);
        $this->assertSame(4, app(Parametros::class)->cupoActivo('estudiante'));

        // RN-06: la clave «usuario» de los días extra pasa a «estudiante».
        $this->assertSame(['encargado' => 2, 'admin' => 3, 'estudiante' => 1], $this->parametroJson('duracion.roles_extra'));
    }

    /** TC-ROL-052 / RN-02: sin parámetros guardados se aplican los cupos iniciales (10/5/3). */
    public function test_tc_rol_052_sin_configuracion_guardada_la_migracion_usa_los_cupos_iniciales(): void
    {
        $this->datosAntiguos(conConfiguracion: false);

        $this->migrar();

        $this->assertSame(
            ['admin' => 10, 'encargado' => 5, 'estudiante' => 3],
            DB::table('roles')->orderBy('name')->pluck('cupo_prestamos', 'name')->map(fn ($c) => (int) $c)->all()
        );
        $this->assertNull($this->parametroJson('duracion.roles_extra'));
    }

    /** TC-ROL-053 / RN-02: el down devuelve «usuario», reescribe cupos.roles y quita las columnas. */
    public function test_tc_rol_053_el_down_restaura_el_rol_usuario_y_la_clave_cupos_roles(): void
    {
        ['usuario' => $usuario, 'rol_id' => $rolId] = $this->datosAntiguos();
        $this->migrar();
        DB::table('roles')->where('name', 'estudiante')->update(['cupo_prestamos' => 7]);

        $this->deshacerHastaAntesDe000008();

        $this->assertFalse(Schema::hasColumn('roles', 'cupo_prestamos'));
        $this->assertFalse(Schema::hasColumn('roles', 'es_predeterminado'));
        $this->assertDatabaseHas('roles', ['id' => $rolId, 'name' => 'usuario']);
        $this->assertSame(['usuario'], DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_id', $usuario->id)->pluck('roles.name')->all());
        $this->assertEquals(['admin' => 12, 'encargado' => 6, 'usuario' => 7], $this->parametroJson('cupos.roles'));
        $this->assertSame(['encargado' => 2, 'admin' => 3, 'usuario' => 1], $this->parametroJson('duracion.roles_extra'));
    }

    /** TC-ROL-054 / RN-02: up → down → up conserva cupos, asignaciones y un único predeterminado. */
    public function test_tc_rol_054_ida_y_vuelta_de_la_migracion_es_estable(): void
    {
        ['usuario' => $usuario] = $this->datosAntiguos();
        $this->migrar();
        DB::table('roles')->where('name', 'estudiante')->update(['cupo_prestamos' => 9]);

        $this->deshacerHastaAntesDe000008();
        $this->migrar();

        $this->assertSame(
            ['admin' => 12, 'encargado' => 6, 'estudiante' => 9],
            DB::table('roles')->orderBy('name')->pluck('cupo_prestamos', 'name')->map(fn ($c) => (int) $c)->all()
        );
        $this->assertSame(['estudiante'], $usuario->fresh()->getRoleNames()->all());
        $this->assertSame(1, DB::table('roles')->where('es_predeterminado', true)->count());
        $this->assertDatabaseMissing('configuraciones', ['clave' => 'cupos.roles']);
        $this->assertSame(['encargado' => 2, 'admin' => 3, 'estudiante' => 1], $this->parametroJson('duracion.roles_extra'));
    }
}

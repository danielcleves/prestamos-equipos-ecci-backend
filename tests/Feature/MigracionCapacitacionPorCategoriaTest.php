<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * RN-01 (capacitación por categoría) — migraciones 2026_11_25_000001 … 000004.
 *
 * Simula una base desplegada con el modelo anterior (users.capacitado y el
 * parámetro global elegibilidad.requiere_capacitacion), aplica las migraciones
 * y comprueba que el parámetro se retira y que nadie queda capacitado; luego
 * prueba el down.
 *
 * Usa DatabaseMigrations (no RefreshDatabase) porque el DDL de MySQL hace
 * commit implícito y rompería la transacción de RefreshDatabase.
 */
class MigracionCapacitacionPorCategoriaTest extends TestCase
{
    use DatabaseMigrations;

    private const PRIMERA = '2026_11_25_000001_add_requiere_capacitacion_to_categorias_table';

    private const CLAVE = 'elegibilidad.requiere_capacitacion';

    protected function setUp(): void
    {
        parent::setUp();

        $this->deshacer();
        $this->assertTrue(Schema::hasColumn('users', 'capacitado'));
        $this->assertFalse(Schema::hasTable('capacitaciones'));
    }

    /**
     * Deshace las migraciones de la capacitación por categoría y cualquier
     * posterior.
     */
    private function deshacer(): void
    {
        $pasos = DB::table('migrations')->where('migration', '>=', self::PRIMERA)->count();
        $this->artisan('migrate:rollback', ['--step' => $pasos])->assertExitCode(0);
    }

    /**
     * Datos «de producción» previos: un usuario capacitado y el parámetro
     * global activado.
     */
    private function datosAntiguos(): int
    {
        $usuario = User::factory()->create();
        DB::table('users')->where('id', $usuario->id)->update(['capacitado' => true]);

        // El down de la 000004 ya repuso la fila (desactivada); se activa
        // como la tendría una base que exigía capacitación.
        DB::table('configuraciones')->updateOrInsert(['clave' => self::CLAVE], [
            'valor' => '1',
            'tipo' => 'bool',
            'grupo' => 'elegibilidad',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $usuario->id;
    }

    public function test_la_migracion_retira_el_parametro_global_y_nadie_queda_capacitado(): void
    {
        $usuarioId = $this->datosAntiguos();
        DB::table('configuraciones')->insert([
            'clave' => 'mora.dias_bloqueo', 'valor' => '2', 'tipo' => 'int', 'grupo' => 'mora',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('categorias')->insert(['nombre' => 'Routers', 'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('migrate')->assertExitCode(0);

        $this->assertDatabaseMissing('configuraciones', ['clave' => self::CLAVE]);
        // Los demás parámetros no se tocan.
        $this->assertDatabaseHas('configuraciones', ['clave' => 'mora.dias_bloqueo', 'valor' => '2']);

        $this->assertFalse(Schema::hasColumn('users', 'capacitado'));
        $this->assertDatabaseCount('capacitaciones', 0);
        $this->assertSame([], User::findOrFail($usuarioId)->idsCategoriasCapacitadas());

        // Las categorías existentes arrancan sin exigir capacitación.
        $this->assertDatabaseHas('categorias', ['nombre' => 'Routers', 'requiere_capacitacion' => false]);
    }

    public function test_el_down_restaura_la_columna_y_el_parametro_con_sus_valores_iniciales(): void
    {
        $usuarioId = $this->datosAntiguos();
        $this->artisan('migrate')->assertExitCode(0);

        $this->deshacer();

        $this->assertFalse(Schema::hasTable('capacitaciones'));
        $this->assertFalse(Schema::hasColumn('categorias', 'requiere_capacitacion'));
        // La columna vuelve con su default (true); el parámetro, desactivado.
        $this->assertTrue((bool) DB::table('users')->where('id', $usuarioId)->value('capacitado'));
        $this->assertDatabaseHas('configuraciones', ['clave' => self::CLAVE, 'valor' => '0', 'tipo' => 'bool']);
    }

    public function test_ida_y_vuelta_es_estable(): void
    {
        $this->datosAntiguos();

        $this->artisan('migrate')->assertExitCode(0);
        $this->deshacer();
        $this->artisan('migrate')->assertExitCode(0);

        $this->assertDatabaseMissing('configuraciones', ['clave' => self::CLAVE]);
        $this->assertTrue(Schema::hasTable('capacitaciones'));
        $this->assertFalse(Schema::hasColumn('users', 'capacitado'));
    }
}

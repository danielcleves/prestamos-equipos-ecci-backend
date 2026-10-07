<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\GuardiaBaseDatos;

class GuardiaBaseDatosTest extends TestCase
{
    public function test_aborta_si_mysql_apunta_a_la_base_de_desarrollo(): void
    {
        $this->assertFalse(GuardiaBaseDatos::esSegura('mysql', 'prestamos_equipos'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("EJECUCIÓN ABORTADA POR SEGURIDAD: La suite de pruebas no puede ejecutarse contra la base de datos 'prestamos_equipos'");

        GuardiaBaseDatos::validar('mysql', 'prestamos_equipos');
    }

    public function test_aborta_si_mysql_tiene_nombre_de_base_vacio_o_nulo(): void
    {
        $this->assertFalse(GuardiaBaseDatos::esSegura('mysql', ''));
        $this->assertFalse(GuardiaBaseDatos::esSegura('mysql', null));

        try {
            GuardiaBaseDatos::validar('mysql', '');
            $this->fail('Se esperaba RuntimeException para base de datos vacía');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('EJECUCIÓN ABORTADA POR SEGURIDAD', $e->getMessage());
        }

        try {
            GuardiaBaseDatos::validar('mysql', null);
            $this->fail('Se esperaba RuntimeException para base de datos nula');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('EJECUCIÓN ABORTADA POR SEGURIDAD', $e->getMessage());
        }
    }

    public function test_aborta_si_el_nombre_contiene_test_pero_no_termina_asi(): void
    {
        $this->assertFalse(GuardiaBaseDatos::esSegura('mysql', 'prestamos_test_copia'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("EJECUCIÓN ABORTADA POR SEGURIDAD: La suite de pruebas no puede ejecutarse contra la base de datos 'prestamos_test_copia'");

        GuardiaBaseDatos::validar('mysql', 'prestamos_test_copia');
    }

    public function test_permite_ejecucion_si_mysql_termina_en_test(): void
    {
        $this->assertTrue(GuardiaBaseDatos::esSegura('mysql', 'prestamos_test'));

        GuardiaBaseDatos::validar('mysql', 'prestamos_test');
    }

    public function test_permite_ejecucion_si_conexion_es_sqlite_en_memoria(): void
    {
        $this->assertTrue(GuardiaBaseDatos::esSegura('sqlite', ':memory:'));

        GuardiaBaseDatos::validar('sqlite', ':memory:');
    }

    public function test_aborta_si_sqlite_usa_archivo_en_disco(): void
    {
        $this->assertFalse(GuardiaBaseDatos::esSegura('sqlite', '/tmp/test.sqlite'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("EJECUCIÓN ABORTADA POR SEGURIDAD: La suite de pruebas en SQLite debe ejecutarse en memoria (':memory:')");

        GuardiaBaseDatos::validar('sqlite', '/tmp/test.sqlite');
    }

    public function test_aborta_si_la_conexion_no_es_sqlite_ni_mysql(): void
    {
        $this->assertFalse(GuardiaBaseDatos::esSegura('pgsql', 'prestamos_test'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("EJECUCIÓN ABORTADA POR SEGURIDAD: Conexión 'pgsql' no permitida para pruebas");

        GuardiaBaseDatos::validar('pgsql', 'prestamos_test');
    }
}

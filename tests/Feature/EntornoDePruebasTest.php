<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Salvaguarda del entorno de pruebas.
 *
 * Al ejecutar dentro de Docker, docker-compose.yml define DB_CONNECTION y
 * DB_DATABASE como variables de entorno del contenedor. Si esas variables se
 * imponen sobre las de phpunit.xml, la suite corre contra la base de datos de
 * desarrollo y RefreshDatabase borra los datos locales en cada corrida.
 *
 * Estas pruebas fallan de inmediato si eso vuelve a ocurrir.
 */
class EntornoDePruebasTest extends TestCase
{
    public function test_las_pruebas_usan_sqlite_y_no_la_base_de_desarrollo(): void
    {
        $this->assertSame(
            'sqlite',
            config('database.default'),
            'Las pruebas NO deben correr contra la base de datos de desarrollo: '
            .'borrarian los datos locales de quien las ejecute.',
        );
    }

    public function test_la_base_de_pruebas_esta_en_memoria(): void
    {
        $this->assertSame(
            ':memory:',
            config('database.connections.sqlite.database'),
        );
    }

    public function test_el_entorno_es_testing(): void
    {
        $this->assertSame('testing', config('app.env'));
    }
}

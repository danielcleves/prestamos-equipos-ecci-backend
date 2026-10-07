<?php

namespace Tests\Feature;

use Tests\Support\GuardiaBaseDatos;
use Tests\TestCase;

/**
 * Salvaguarda del entorno de pruebas.
 *
 * Al ejecutar dentro de Docker, docker-compose.yml define variables de entorno
 * del contenedor. Si esas variables se imponen sobre las de testing o apuntan a la
 * base de desarrollo, RefreshDatabase borraría los datos locales en cada corrida.
 *
 * Estas pruebas garantizan que la suite solo pueda correr bajo configuraciones
 * estrictamente seguras e identificadas como de prueba.
 */
class EntornoDePruebasTest extends TestCase
{
    public function test_la_conexion_y_base_de_pruebas_cumplen_con_la_guardia_de_seguridad(): void
    {
        $conexion = config('database.default');
        $database = config("database.connections.{$conexion}.database");

        $this->assertTrue(
            GuardiaBaseDatos::esSegura($conexion, $database),
            'Las pruebas NO deben correr contra una configuración de base de datos no segura: '
            .'borrarían los datos locales de quien las ejecute. Conexión: '.$conexion.', Base: '.$database,
        );
    }

    public function test_la_base_de_pruebas_activa_no_es_la_base_de_desarrollo(): void
    {
        $conexion = config('database.default');
        $database = config("database.connections.{$conexion}.database");
        $baseDesarrollo = $this->baseDeDatosDesarrollo();

        $this->assertNotSame(
            $baseDesarrollo,
            $database,
            'Las pruebas NO deben correr contra la base de datos de desarrollo: '
            .'borrarían los datos locales de quien las ejecute.',
        );
    }

    public function test_el_entorno_de_aplicacion_es_testing(): void
    {
        $this->assertSame('testing', config('app.env'));
    }

    private function baseDeDatosDesarrollo(): string
    {
        $envExample = base_path('.env.example');
        if (file_exists($envExample)) {
            $lineas = file($envExample, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lineas as $linea) {
                if (str_starts_with(trim($linea), 'DB_DATABASE=')) {
                    return trim(explode('=', $linea, 2)[1]);
                }
            }
        }

        return 'prestamos_equipos';
    }
}

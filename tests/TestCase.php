<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\GuardiaBaseDatos;

abstract class TestCase extends BaseTestCase
{
    /**
     * Intercepta la inicialización de traits de prueba una vez arrancada la aplicación.
     * Se ejecuta después de bootear el framework y antes de que RefreshDatabase
     * realice cualquier migración o consulta sobre la base de datos.
     */
    protected function setUpTraits()
    {
        $conexion = config('database.default');
        $database = config("database.connections.{$conexion}.database");

        GuardiaBaseDatos::validar($conexion, $database);

        return parent::setUpTraits();
    }
}

<?php

namespace Tests\Support;

use RuntimeException;

class GuardiaBaseDatos
{
    /**
     * Determina si la conexión y la base de datos corresponden a un entorno seguro de pruebas.
     * Casos válidos permitidos únicamente:
     * - conexión sqlite con base ':memory:'
     * - conexión mysql con nombre terminado en '_test'
     */
    public static function esSegura(?string $conexion, ?string $database): bool
    {
        $conexionNormalizada = $conexion !== null ? strtolower(trim($conexion)) : null;

        if ($conexionNormalizada === 'sqlite') {
            return $database === ':memory:';
        }

        if ($conexionNormalizada === 'mysql') {
            if ($database === null || trim($database) === '') {
                return false;
            }

            return str_ends_with(strtolower(trim($database)), '_test');
        }

        return false;
    }

    /**
     * Valida que la conexión y el nombre de la base de datos sean seguros para ejecutar pruebas.
     * Lanza RuntimeException si no cumple con las condiciones estrictas de seguridad.
     *
     * @throws RuntimeException
     */
    public static function validar(?string $conexion, ?string $database): void
    {
        $conexionNormalizada = $conexion !== null ? strtolower(trim($conexion)) : null;

        if ($conexionNormalizada === 'sqlite') {
            if ($database !== ':memory:') {
                throw new RuntimeException(
                    "EJECUCIÓN ABORTADA POR SEGURIDAD: La suite de pruebas en SQLite debe ejecutarse en memoria (':memory:'). Base actual: '{$database}'."
                );
            }

            return;
        }

        if ($conexionNormalizada === 'mysql') {
            if ($database === null || trim($database) === '') {
                throw new RuntimeException(
                    'EJECUCIÓN ABORTADA POR SEGURIDAD: La conexión MySQL no tiene configurado el nombre de la base de datos. Debe especificarse una base de datos de prueba que termine estrictamente en \'_test\'.'
                );
            }

            if (! str_ends_with(strtolower(trim($database)), '_test')) {
                throw new RuntimeException(
                    "EJECUCIÓN ABORTADA POR SEGURIDAD: La suite de pruebas no puede ejecutarse contra la base de datos '{$database}'. Al usar MySQL, el nombre de la base de datos DEBE terminar estrictamente en '_test' para proteger la base de datos de desarrollo."
                );
            }

            return;
        }

        throw new RuntimeException(
            "EJECUCIÓN ABORTADA POR SEGURIDAD: Conexión '{$conexion}' no permitida para pruebas. Solo se permite 'sqlite' con ':memory:' o 'mysql' terminado en '_test'."
        );
    }
}

<?php

namespace Tests\Support;

use App\Models\Configuracion;

/**
 * Helper de pruebas para fijar parámetros de negocio sin depender de los
 * valores del seed.
 */
trait ConfiguraParametros
{
    protected function parametro(string $clave, mixed $valor): void
    {
        Configuracion::establecer($clave, $valor);
    }
}

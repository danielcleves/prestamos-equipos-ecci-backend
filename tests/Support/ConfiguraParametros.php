<?php

namespace Tests\Support;

use App\Models\Configuracion;
use App\Models\Rol;
use App\Services\Parametros;

/**
 * Helper de pruebas para fijar parámetros de negocio sin depender de los
 * valores del seed.
 */
trait ConfiguraParametros
{
    protected function parametro(string $clave, mixed $valor): void
    {
        // RN-02: el cupo por rol vive en la tabla de roles.
        if ($clave === Parametros::CLAVE_CUPOS) {
            foreach ($valor as $rol => $cupo) {
                $this->cupo((string) $rol, (int) $cupo);
            }

            return;
        }

        Configuracion::establecer($clave, $valor);
    }

    /**
     * Fija el cupo de un rol, creándolo si no existe.
     */
    protected function cupo(string $rol, int $cupo): Rol
    {
        $modelo = Rol::firstOrCreate(['name' => $rol, 'guard_name' => 'web']);
        $modelo->update(['cupo_prestamos' => $cupo]);

        return $modelo;
    }
}

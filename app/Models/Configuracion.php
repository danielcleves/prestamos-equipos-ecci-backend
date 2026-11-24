<?php

namespace App\Models;

use App\Services\Parametros;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Parámetro de negocio administrable (iteración 2).
 *
 * 'valor' se guarda como texto; 'tipo' decide cómo interpretarlo. La lista de
 * claves válidas y sus valores iniciales viven en Parametros::DEFINICIONES.
 */
#[Fillable(['clave', 'valor', 'tipo', 'grupo', 'descripcion'])]
class Configuracion extends Model
{
    protected $table = 'configuraciones';

    /**
     * Crea o actualiza un parámetro conocido a partir de su valor PHP.
     * La usan el endpoint de configuración y las pruebas.
     */
    public static function establecer(string $clave, mixed $valor): self
    {
        $definicion = Parametros::DEFINICIONES[$clave];

        return static::updateOrCreate(
            ['clave' => $clave],
            [
                'valor' => Parametros::codificar($valor, $definicion['tipo']),
                'tipo' => $definicion['tipo'],
                'grupo' => $definicion['grupo'],
                'descripcion' => $definicion['descripcion'],
            ]
        );
    }
}

<?php

namespace Database\Seeders;

use App\Models\Categoria;
use App\Models\Configuracion;
use App\Services\Parametros;
use Illuminate\Database\Seeder;

/**
 * Valores iniciales de los parámetros de negocio de la iteración 2.
 *
 * firstOrCreate y no updateOrCreate: nunca sobreescribe los valores que el
 * admin haya ajustado desde la pantalla de configuración.
 */
class ConfiguracionSeeder extends Seeder
{
    /**
     * Duración inicial por nombre de categoría del seed (Portátil, Tablet,
     * De mesa). Se resuelve por nombre para no depender de los ids.
     */
    private const DURACION_POR_NOMBRE = [
        'Portátil' => 7,
        'Tablet' => 5,
        'De mesa' => 3,
    ];

    public function run(): void
    {
        foreach (Parametros::DEFINICIONES as $clave => $definicion) {
            $valor = $clave === 'duracion.categorias'
                ? $this->duracionPorCategoria()
                : $definicion['default'];

            Configuracion::firstOrCreate(
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

    /**
     * @return array<string, int>
     */
    private function duracionPorCategoria(): array
    {
        $ids = Categoria::whereIn('nombre', array_keys(self::DURACION_POR_NOMBRE))
            ->pluck('id', 'nombre');

        $duracion = [];
        foreach (self::DURACION_POR_NOMBRE as $nombre => $dias) {
            if (isset($ids[$nombre])) {
                $duracion[(string) $ids[$nombre]] = $dias;
            }
        }

        return $duracion !== [] ? $duracion : Parametros::DEFINICIONES['duracion.categorias']['default'];
    }
}

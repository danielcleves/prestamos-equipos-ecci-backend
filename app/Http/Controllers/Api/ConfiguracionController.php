<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateConfiguracionRequest;
use App\Models\Configuracion;
use App\Services\Parametros;
use Illuminate\Http\JsonResponse;

/**
 * Configuración de los parámetros de negocio (iteración 2). Exclusivo del rol
 * admin: el PO decidió que los valores son administrables desde el sistema.
 */
class ConfiguracionController extends Controller
{
    /**
     * Lista los parámetros efectivos agrupados por grupo (cupos, duracion,
     * mora, danos, elegibilidad). Incluye los valores por defecto cuando una
     * clave todavía no se ha guardado.
     */
    public function show(Parametros $parametros): JsonResponse
    {
        return response()->json(['data' => $parametros->porGrupos()]);
    }

    /**
     * Actualiza un mapa «clave => valor». Las claves desconocidas son un 422.
     */
    public function update(UpdateConfiguracionRequest $request): JsonResponse
    {
        foreach ($request->parametros() as $clave => $valor) {
            Configuracion::establecer($clave, $valor);
        }

        // Instancia nueva para devolver los valores recién guardados (la
        // inyectada podría tener el caché de la lectura previa).
        $parametros = app(Parametros::class);

        return response()->json(['data' => $parametros->porGrupos()]);
    }
}

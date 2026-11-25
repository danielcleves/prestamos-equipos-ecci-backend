<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateConfiguracionRequest;
use App\Http\Resources\RolResource;
use App\Models\Categoria;
use App\Models\Configuracion;
use App\Models\Rol;
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
     * mora, danos, registro). Incluye los valores por defecto cuando una
     * clave todavía no se ha guardado.
     */
    public function show(Parametros $parametros): JsonResponse
    {
        // H-11: la pantalla de configuración necesita el catálogo real de
        // categorías para mostrar su nivel de restricción actual (RN-03).
        return response()->json([
            'data' => [
                ...$parametros->porGrupos(),
                // RN-01: también si exige capacitación.
                'categorias' => Categoria::orderBy('id')
                    ->get(['id', 'nombre', 'nivel_restriccion', 'requiere_capacitacion'])
                    ->toArray(),
                // RN-02/RN-06: catálogo de roles para pintar cupos y días
                // extra de todos los roles, también los personalizados.
                'roles' => RolResource::collection(Rol::withCount('users')->orderBy('id')->get())
                    ->resolve(),
            ],
        ]);
    }

    /**
     * Actualiza un mapa «clave => valor». Las claves desconocidas son un 422.
     */
    public function update(UpdateConfiguracionRequest $request): JsonResponse
    {
        foreach ($request->parametros() as $clave => $valor) {
            if ($clave === Parametros::CLAVE_CUPOS) {
                // RN-02: el cupo vive en la tabla de roles.
                foreach ($valor as $rol => $cupo) {
                    Rol::where('name', (string) $rol)->update(['cupo_prestamos' => (int) $cupo]);
                }

                continue;
            }

            Configuracion::establecer($clave, $valor);
        }

        // Instancia nueva para devolver los valores recién guardados (la
        // inyectada podría tener el caché de la lectura previa).
        $parametros = app(Parametros::class);

        return response()->json(['data' => $parametros->porGrupos()]);
    }
}

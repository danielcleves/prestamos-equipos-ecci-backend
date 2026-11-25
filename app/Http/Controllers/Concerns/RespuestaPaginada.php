<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrato único de los listados paginados de la API.
 *
 * Todos los endpoints que paginan (préstamos, equipos, historial y revisiones)
 * responden con la misma forma: 'data' con la colección del recurso y 'meta'
 * con current_page / last_page / per_page / total. Tenerlo en un solo lugar
 * evita que cada controlador acote 'per_page' a su manera y que el bloque
 * 'meta' se escriba una vez por listado.
 */
trait RespuestaPaginada
{
    /**
     * Construye la respuesta paginada estándar del módulo: per_page acotado a
     * [1, 100] con default 15, y el mismo bloque 'meta' en todos los listados.
     *
     * El max() evita que un per_page de 0 o negativo llegue a paginate():
     * MySQL rechaza un LIMIT negativo con un 500, y con 0 el paginator
     * devuelve meta.last_page = 0.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>|Relation<TModel, TModel, *>  $query
     * @param  class-string<JsonResource>  $recurso
     */
    protected function respuestaPaginada(Builder|Relation $query, Request $request, string $recurso): JsonResponse
    {
        $perPage = max(1, min($request->integer('per_page', 15), 100));

        $pagina = $query->paginate($perPage)->withQueryString();

        return response()->json([
            'data' => $recurso::collection($pagina),
            'meta' => [
                'current_page' => $pagina->currentPage(),
                'last_page' => $pagina->lastPage(),
                'per_page' => $pagina->perPage(),
                'total' => $pagina->total(),
            ],
        ]);
    }
}

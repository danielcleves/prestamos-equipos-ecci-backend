<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateCategoriaRestriccionRequest;
use App\Http\Resources\CategoriaResource;
use App\Models\Categoria;
use Illuminate\Http\JsonResponse;

/**
 * RN-03: nivel de restricción por categoría, administrable solo por admin.
 */
class CategoriaController extends Controller
{
    public function actualizarRestriccion(UpdateCategoriaRestriccionRequest $request, Categoria $categoria): JsonResponse
    {
        $categoria->update([
            'nivel_restriccion' => (int) $request->validated('nivel_restriccion'),
        ]);

        return response()->json(['data' => new CategoriaResource($categoria)]);
    }
}

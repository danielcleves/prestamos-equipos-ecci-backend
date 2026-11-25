<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateCategoriaRestriccionRequest;
use App\Http\Resources\CategoriaResource;
use App\Models\Categoria;
use Illuminate\Http\JsonResponse;

/**
 * RN-01 / RN-03: nivel de restricción y requisito de capacitación por
 * categoría, administrables solo por admin.
 */
class CategoriaController extends Controller
{
    public function actualizarRestriccion(UpdateCategoriaRestriccionRequest $request, Categoria $categoria): JsonResponse
    {
        $categoria->update($request->validated());

        return response()->json(['data' => new CategoriaResource($categoria)]);
    }
}

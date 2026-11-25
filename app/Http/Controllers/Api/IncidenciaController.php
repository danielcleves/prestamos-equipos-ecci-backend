<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\RespuestaPaginada;
use App\Http\Controllers\Controller;
use App\Http\Resources\IncidenciaResource;
use App\Models\Incidencia;
use App\Services\IncidenciaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * RN-08: consulta y cierre de las incidencias abiertas por daños.
 * Exclusivo del personal administrativo (middleware role:admin|encargado).
 */
class IncidenciaController extends Controller
{
    use RespuestaPaginada;

    /**
     * Lista las incidencias, más recientes primero. Filtros opcionales:
     * ?estado=, ?equipo_id= y ?user_id=.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Incidencia::with(['equipo', 'solicitante']);

        if ($request->filled('estado')) {
            $estado = $request->string('estado')->toString();

            if (! in_array($estado, Incidencia::ESTADOS, true)) {
                throw ValidationException::withMessages([
                    'estado' => 'El estado de la incidencia no es válido.',
                ]);
            }

            $query->where('estado', $estado);
        }

        if ($request->filled('equipo_id')) {
            $query->where('equipo_id', $request->integer('equipo_id'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        return $this->respuestaPaginada($query->latest('id'), $request, IncidenciaResource::class);
    }

    /**
     * Cierra la incidencia y deja de restringir al solicitante.
     */
    public function cerrar(Request $request, Incidencia $incidencia, IncidenciaService $service): JsonResponse
    {
        $cerrada = $service->cerrar($incidencia, $request->user());

        return response()->json([
            'data' => new IncidenciaResource($cerrada->load(['equipo', 'solicitante'])),
        ]);
    }
}

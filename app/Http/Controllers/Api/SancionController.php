<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\RespuestaPaginada;
use App\Http\Controllers\Controller;
use App\Http\Resources\SancionResource;
use App\Models\Sancion;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RN-04: consulta de sanciones de un usuario. Solo lectura y exclusiva del
 * personal administrativo (middleware role:admin|encargado).
 *
 * Las sanciones las crea SancionService al registrar una devolución tardía;
 * aquí no se crean, cierran ni cambian de estado: el paso de 'activa' a
 * 'cumplida' de una sanción vencida lo sigue haciendo ElegibilidadService
 * cuando el usuario intenta solicitar.
 */
class SancionController extends Controller
{
    use RespuestaPaginada;

    /**
     * Lista las sanciones del usuario con las 'activa' primero y, dentro del
     * grupo, las que vencen más tarde antes (la vigente encabeza la lista).
     */
    public function index(Request $request, User $usuario): JsonResponse
    {
        // Sin usar la relación 'sanciones()': su latest('id') quedaría como
        // primer ORDER BY y rompería el orden de activas primero.
        $query = Sancion::where('user_id', $usuario->id)
            ->orderByRaw('CASE WHEN estado = ? THEN 0 ELSE 1 END', [Sancion::ESTADO_ACTIVA])
            ->orderByDesc('fecha_fin')
            ->orderByDesc('id');

        return $this->respuestaPaginada($query, $request, SancionResource::class);
    }
}

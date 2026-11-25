<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreRolRequest;
use App\Http\Requests\Api\UpdateRolRequest;
use App\Http\Resources\RolResource;
use App\Models\Rol;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * RN-02 — Roles personalizados y su cupo de préstamos vivos.
 *
 * Exclusivo del admin. Los roles de sistema (admin, encargado) solo admiten
 * cambiar etiqueta, descripción y cupo; los de solicitante (estudiante,
 * profesor, coordinador…) se pueden crear y eliminar.
 */
class RolController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = Rol::withCount('users')->orderBy('id')->get();

        return response()->json(['data' => RolResource::collection($roles)]);
    }

    public function store(StoreRolRequest $request): JsonResponse
    {
        $datos = $request->validated();

        $rol = DB::transaction(function () use ($request, $datos) {
            $predeterminado = (bool) ($datos['es_predeterminado'] ?? false);

            if ($predeterminado) {
                Rol::where('es_predeterminado', true)->update(['es_predeterminado' => false]);
            }

            return Rol::create([
                'name' => $request->nombre(),
                'guard_name' => 'web',
                'etiqueta' => $datos['etiqueta'],
                'descripcion' => $datos['descripcion'] ?? null,
                'cupo_prestamos' => (int) $datos['cupo_prestamos'],
                'es_predeterminado' => $predeterminado,
            ]);
        });

        $rol->loadCount('users');

        return response()->json(['data' => new RolResource($rol)], 201);
    }

    public function show(Rol $rol): JsonResponse
    {
        $rol->loadCount('users');

        return response()->json(['data' => new RolResource($rol)]);
    }

    public function update(UpdateRolRequest $request, Rol $rol): JsonResponse
    {
        $datos = $request->validated();

        DB::transaction(function () use ($rol, $datos) {
            if (($datos['es_predeterminado'] ?? false) && ! $rol->es_predeterminado) {
                Rol::whereKeyNot($rol->id)->where('es_predeterminado', true)
                    ->update(['es_predeterminado' => false]);
            }

            $rol->fill($datos)->save();
        });

        $rol->refresh()->loadCount('users');

        return response()->json(['data' => new RolResource($rol)]);
    }

    public function destroy(Rol $rol): JsonResponse
    {
        if ($rol->esSistema()) {
            return response()->json(['message' => 'Los roles de sistema no se pueden eliminar.'], 422);
        }

        if ($rol->es_predeterminado) {
            return response()->json([
                'message' => 'No puedes eliminar el rol predeterminado. Marca otro rol como predeterminado primero.',
            ], 422);
        }

        $usuarios = $rol->users()->count();

        if ($usuarios > 0) {
            return response()->json([
                'message' => "El rol tiene {$usuarios} usuario(s) asignado(s). Cámbiales el rol antes de eliminarlo.",
            ], 422);
        }

        $rol->delete();

        return response()->json(null, 204);
    }
}

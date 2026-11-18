<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\RespuestaPaginada;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\IndexEquipoRequest;
use App\Http\Requests\Api\StoreEquipoRequest;
use App\Http\Requests\Api\UpdateEquipoEstadoRequest;
use App\Http\Resources\EquipoResource;
use App\Http\Resources\HistorialEstadoResource;
use App\Http\Resources\RevisionEquipoResource;
use App\Models\Equipo;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EquipoController extends Controller
{
    use RespuestaPaginada;

    public function index(IndexEquipoRequest $request): JsonResponse
    {
        $query = Equipo::with('categoria')
            ->when(! Equipo::puedeVerDadosDeBaja($request->user()), fn ($q) => $q->visiblesEnCatalogo())
            ->when($request->filled('categoria_id'), fn ($q) => $q->where('categoria_id', $request->integer('categoria_id')))
            ->when($request->filled('disponible'), fn ($q) => $request->boolean('disponible') ? $q->disponible() : $q->noDisponible())
            ->when($request->filled('buscar'), fn ($q) => $q->buscar($request->string('buscar')->toString()));

        $orden = $request->filled('ordenar') ? $request->input('ordenar') : 'nombre';

        match ($orden) {
            '-nombre' => $query->orderByDesc('nombre')->orderByDesc('id'),
            'disponibles_primero' => $query
                ->orderByRaw('CASE WHEN estado = ? THEN 0 ELSE 1 END', [Equipo::ESTADO_DISPONIBLE])
                ->orderBy('nombre')
                ->orderBy('id'),
            'recientes' => $query->orderByDesc('created_at')->orderByDesc('id'),
            default => $query->orderBy('nombre')->orderBy('id'),
        };

        return $this->respuestaPaginada($query, $request, EquipoResource::class);
    }

    public function store(StoreEquipoRequest $request): JsonResponse
    {
        $data = $request->validated();

        $equipo = Equipo::create([
            ...$data,
            'estado' => Equipo::ESTADO_INICIAL,
        ]);

        return response()->json(['data' => new EquipoResource($equipo->load('categoria'))], 201);
    }

    public function show(Request $request, Equipo $equipo): JsonResponse
    {
        $this->asegurarVisibilidad($request->user(), $equipo);

        return response()->json(['data' => new EquipoResource($equipo->load('categoria'))]);
    }

    public function actualizarEstado(UpdateEquipoEstadoRequest $request, Equipo $equipo): JsonResponse
    {
        $equipo->update(['estado' => $request->validated('estado')]);

        return response()->json(['data' => new EquipoResource($equipo->load('categoria'))]);
    }

    public function historial(Request $request, Equipo $equipo): JsonResponse
    {
        $this->asegurarVisibilidad($request->user(), $equipo);

        $historial = $equipo->historialEstados()->with('usuario');

        return $this->respuestaPaginada($historial, $request, HistorialEstadoResource::class);
    }

    /**
     * HU-12 / KAN-130: revisiones de devolución del equipo, más recientes
     * primero. Endpoint propio y exclusivo de personal administrativo: no se
     * suma a /historial porque ese sí lo consulta cualquier autenticado y las
     * observaciones de la revisión son reservadas.
     *
     * Las revisiones se conservan después de cerrado el préstamo: son la
     * trazabilidad que alimenta la HU-31 (incidencias).
     */
    public function revisiones(Request $request, Equipo $equipo): JsonResponse
    {
        $revisiones = $equipo->revisiones();

        // Filtro opcional para aislar las devoluciones con novedad.
        if ($request->filled('con_danos')) {
            $revisiones->where('tiene_danos', $request->boolean('con_danos'));
        }

        return $this->respuestaPaginada($revisiones, $request, RevisionEquipoResource::class);
    }

    private function asegurarVisibilidad(?User $user, Equipo $equipo): void
    {
        if (! Equipo::puedeVerDadosDeBaja($user) && $equipo->isDadoDeBaja()) {
            throw (new ModelNotFoundException)->setModel(Equipo::class, [$equipo->id]);
        }
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EquipoResource extends JsonResource
{
    /**
     * Clave del atributo de la petición donde se guardan los ids de las
     * categorías del usuario autenticado: así un listado consulta una sola
     * vez, sin importar cuántos equipos pinte.
     */
    private const CACHE_CAPACITACIONES = 'equipo_resource.categorias_capacitadas';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $disponible = $this->resource->isDisponible();

        return [
            'id' => $this->id,
            'codigo' => $this->codigo,
            'nombre' => $this->nombre,
            'categoria' => $this->whenLoaded('categoria', fn () => [
                'id' => $this->categoria->id,
                'nombre' => $this->categoria->nombre,
                // RN-01 / RN-03: 0 libre, 1 requiere autorización, 2 solo
                // personal administrativo.
                'nivel_restriccion' => (int) $this->categoria->nivel_restriccion,
                // RN-01: exige capacitación en la categoría para solicitar.
                'requiere_capacitacion' => (bool) $this->categoria->requiere_capacitacion,
            ]),
            // RN-01: si el usuario autenticado puede pedirlo por capacitación
            // (la categoría no la exige o está capacitado en ella).
            'usuario_capacitado' => $this->whenLoaded(
                'categoria',
                fn () => $this->usuarioCapacitado($request)
            ),
            // RN-06: solo se expone en el detalle del equipo, donde el
            // controlador calcula el límite según categoría y rol. En los
            // listados (o anidado en un préstamo) se omite para no multiplicar
            // consultas de parámetros por fila.
            'duracion_maxima_dias' => $this->whenNotNull($this->duracion_maxima_dias),
            'descripcion' => $this->descripcion,
            // Ubicación física opcional (bodega, sala, laboratorio).
            'ubicacion' => $this->ubicacion,
            'disponible' => $disponible,
            'puede_solicitarse' => $disponible,
            'estado_disponibilidad' => $disponible ? 'disponible' : 'no_disponible',
            'estado' => $this->estado,
            'observaciones' => $this->when(
                $request->user()?->esPersonalAdministrativo() ?? false,
                $this->observaciones
            ),
        ];
    }

    private function usuarioCapacitado(Request $request): bool
    {
        $categoria = $this->categoria;

        if ($categoria === null || ! $categoria->requiere_capacitacion) {
            return true;
        }

        $usuario = $request->user();

        if ($usuario === null) {
            return false;
        }

        if (! $request->attributes->has(self::CACHE_CAPACITACIONES)) {
            $request->attributes->set(self::CACHE_CAPACITACIONES, $usuario->idsCategoriasCapacitadas());
        }

        return in_array((int) $categoria->id, $request->attributes->get(self::CACHE_CAPACITACIONES), true);
    }
}

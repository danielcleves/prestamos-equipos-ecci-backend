<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreEquipoRequest extends FormRequest
{
    public function authorize(): bool
    {
        // La autorizacion (solo admin) la resuelve el middleware 'role:admin'
        // de la ruta.
        return true;
    }

    /**
     * No se valida 'estado': el sistema lo asigna solo al registrar
     * (Equipo::ESTADO_INICIAL), no lo elige quien registra el equipo.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'codigo' => ['required', 'string', 'max:255', 'unique:equipos,codigo'],
            'nombre' => ['required', 'string', 'max:255'],
            'categoria_id' => ['required', 'integer', 'exists:categorias,id'],
            'descripcion' => ['nullable', 'string'],
            'observaciones' => ['nullable', 'string'],
        ];
    }
}

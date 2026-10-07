<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class IndexEquipoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'categoria_id' => ['sometimes', 'nullable', 'integer', 'exists:categorias,id'],
            'disponible' => ['sometimes', 'nullable', 'in:true,false,1,0'],
            'buscar' => ['sometimes', 'nullable', 'string', 'max:100'],
            'ordenar' => ['sometimes', 'nullable', 'in:nombre,-nombre,disponibles_primero,recientes'],
        ];
    }
}

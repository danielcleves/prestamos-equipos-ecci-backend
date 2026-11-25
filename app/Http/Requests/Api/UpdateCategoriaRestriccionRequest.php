<?php

namespace App\Http\Requests\Api;

use App\Models\Categoria;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoriaRestriccionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // La autorización (solo admin) la resuelve el middleware 'role:admin'.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nivel_restriccion' => ['required', 'integer', Rule::in(Categoria::NIVELES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nivel_restriccion.required' => 'El nivel de restricción es obligatorio.',
            'nivel_restriccion.integer' => 'El nivel de restricción debe ser un número entero.',
            'nivel_restriccion.in' => 'El nivel de restricción debe ser 0 (libre), 1 (requiere autorización) o 2 (solo personal).',
        ];
    }
}

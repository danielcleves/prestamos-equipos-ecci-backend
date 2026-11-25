<?php

namespace App\Http\Requests\Api;

use App\Models\Categoria;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/categorias/{categoria}/restriccion.
 *
 * Ajusta el nivel de restricción (RN-01, RN-03) y si la categoría exige
 * capacitación (RN-01). Ambos son opcionales, pero debe venir al menos uno.
 */
class UpdateCategoriaRestriccionRequest extends FormRequest
{
    /**
     * Campos que puede cambiar este endpoint.
     */
    public const CAMPOS = ['nivel_restriccion', 'requiere_capacitacion'];

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
            'nivel_restriccion' => ['sometimes', 'required', 'integer', Rule::in(Categoria::NIVELES)],
            'requiere_capacitacion' => ['sometimes', 'required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->hasAny(self::CAMPOS)) {
                $validator->errors()->add(
                    'nivel_restriccion',
                    'Debes enviar el nivel de restricción o si la categoría requiere capacitación.'
                );
            }
        });
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
            'requiere_capacitacion.required' => 'Indica si la categoría requiere capacitación.',
            'requiere_capacitacion.boolean' => 'El campo «requiere capacitación» debe ser verdadero o falso.',
        ];
    }
}

<?php

namespace App\Http\Requests\Api;

use App\Enums\CondicionEquipo;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DevolucionPrestamoRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->esPersonalAdministrativo();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'fecha_devolucion_real' => ['nullable', 'date'],
            'condicion_devolucion' => ['required', Rule::enum(CondicionEquipo::class)],
            'observaciones' => [
                Rule::requiredIf(fn () => $this->input('condicion_devolucion') !== CondicionEquipo::Bueno->value),
                'nullable',
                'string',
                'max:2000',
                function (string $attribute, mixed $value, Closure $fail) {
                    $condicion = $this->input('condicion_devolucion');
                    if ($condicion !== CondicionEquipo::Bueno->value && is_string($value) && trim($value) === '') {
                        $fail('Las observaciones son obligatorias cuando el equipo no se devuelve en buen estado.');
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fecha_devolucion_real.date' => 'La fecha de devolución real debe ser una fecha y hora válida.',
            'condicion_devolucion.required' => 'La condición de devolución es obligatoria.',
            'condicion_devolucion.enum' => 'La condición de devolución seleccionada es inválida.',
            'observaciones.required' => 'Las observaciones son obligatorias cuando el equipo no se devuelve en buen estado.',
            'observaciones.max' => 'Las observaciones no pueden superar los 2000 caracteres.',
        ];
    }
}

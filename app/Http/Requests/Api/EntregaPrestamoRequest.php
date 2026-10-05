<?php

namespace App\Http\Requests\Api;

use App\Enums\CondicionEquipo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EntregaPrestamoRequest extends FormRequest
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
            'fecha_entrega_real' => ['nullable', 'date'],
            'condicion_entrega' => ['nullable', Rule::enum(CondicionEquipo::class)],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fecha_entrega_real.date' => 'La fecha de entrega real debe ser una fecha y hora válida.',
            'condicion_entrega.enum' => 'La condición de entrega seleccionada es inválida.',
            'observaciones.max' => 'Las observaciones no pueden superar los 2000 caracteres.',
        ];
    }
}

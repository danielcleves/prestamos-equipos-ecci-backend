<?php

namespace App\Http\Requests\Api;

use App\Rules\TextoNoVacio;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RechazoPrestamoRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'motivo' => [
                'required',
                'string',
                'max:1000',
                new TextoNoVacio,
            ],
        ];
    }

    /**
     * Mensajes de validación en español neutro.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo.required' => 'El motivo del rechazo es obligatorio.',
            'motivo.string' => 'El motivo del rechazo debe ser una cadena de texto.',
            'motivo.max' => 'El motivo del rechazo no puede superar los 1000 caracteres.',
        ];
    }
}

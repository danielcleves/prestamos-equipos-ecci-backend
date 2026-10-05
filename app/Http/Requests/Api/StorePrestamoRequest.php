<?php

namespace App\Http\Requests\Api;

use Carbon\Carbon;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePrestamoRequest extends FormRequest
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
            'equipo_id' => ['required', 'integer', 'exists:equipos,id'],
            'motivo' => [
                'required',
                'string',
                'max:1000',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (is_string($value) && trim($value) === '') {
                        $fail('El motivo no puede estar vacío ni contener solo espacios.');
                    }
                },
            ],
            'fecha_inicio' => [
                'required',
                'date',
                function (string $attribute, mixed $value, Closure $fail) {
                    try {
                        $fecha = Carbon::parse($value);
                        // Tolerancia de 60 segundos para evitar carreras por latencia en el servidor
                        if ($fecha->isBefore(now()->subMinute())) {
                            $fail('La fecha y hora de inicio debe ser igual o posterior al momento actual.');
                        }
                    } catch (\Throwable) {
                        $fail('La fecha de inicio debe ser una fecha y hora válida.');
                    }
                },
            ],
            'fecha_devolucion_estimada' => [
                'required',
                'date',
                'after:fecha_inicio',
                function (string $attribute, mixed $value, Closure $fail) {
                    $fechaInicioStr = $this->input('fecha_inicio');
                    if (! $fechaInicioStr) {
                        return;
                    }

                    try {
                        $inicio = Carbon::parse($fechaInicioStr);
                        $devolucion = Carbon::parse($value);
                        $maxDias = (int) config('prestamos.duracion_maxima_dias', 7);

                        if ($devolucion->greaterThan($inicio->copy()->addDays($maxDias))) {
                            $fail("La fecha de devolución estimada no puede superar los {$maxDias} días posteriores a la fecha de inicio.");
                        }
                    } catch (\Throwable) {
                        // Error de formato capturado por la regla 'date'
                    }
                },
            ],
        ];
    }

    /**
     * Mensajes de error en español neutro.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'equipo_id.required' => 'El equipo es obligatorio.',
            'equipo_id.integer' => 'El identificador del equipo debe ser válido.',
            'equipo_id.exists' => 'El equipo seleccionado no existe.',
            'motivo.required' => 'El motivo del préstamo es obligatorio.',
            'motivo.string' => 'El motivo del préstamo debe ser una cadena de texto.',
            'motivo.max' => 'El motivo del préstamo no puede superar los 1000 caracteres.',
            'fecha_inicio.required' => 'La fecha de inicio es obligatoria.',
            'fecha_inicio.date' => 'La fecha de inicio debe ser una fecha y hora válida.',
            'fecha_devolucion_estimada.required' => 'La fecha de devolución estimada es obligatoria.',
            'fecha_devolucion_estimada.date' => 'La fecha de devolución estimada debe ser una fecha y hora válida.',
            'fecha_devolucion_estimada.after' => 'La fecha de devolución estimada debe ser posterior a la fecha de inicio.',
        ];
    }
}

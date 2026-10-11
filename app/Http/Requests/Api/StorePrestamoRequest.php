<?php

namespace App\Http\Requests\Api;

use App\Rules\TextoNoVacio;
use App\Support\FechaNegocio;
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
                new TextoNoVacio,
            ],
            'fecha_inicio' => [
                'required',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (! FechaNegocio::esValida($value)) {
                        $fail('La fecha y hora de inicio debe tener un formato válido (Y-m-d H:i, Y-m-d H:i:s, o ISO 8601 con o sin desplazamiento).');

                        return;
                    }

                    $fecha = FechaNegocio::parsear($value);
                    // Tolerancia de 60 segundos para evitar carreras por latencia en el servidor
                    if ($fecha->isBefore(now()->subMinute())) {
                        $fail('La fecha y hora de inicio debe ser igual o posterior al momento actual.');
                    }
                },
            ],
            'fecha_devolucion_estimada' => [
                'required',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (! FechaNegocio::esValida($value)) {
                        $fail('La fecha y hora de devolución estimada debe tener un formato válido (Y-m-d H:i, Y-m-d H:i:s, o ISO 8601 con o sin desplazamiento).');

                        return;
                    }

                    $fechaInicioStr = $this->input('fecha_inicio');
                    if (! FechaNegocio::esValida($fechaInicioStr)) {
                        return;
                    }

                    $inicio = FechaNegocio::parsear($fechaInicioStr);
                    $devolucion = FechaNegocio::parsear($value);

                    if ($devolucion->lessThanOrEqualTo($inicio)) {
                        $fail('La fecha de devolución estimada debe ser posterior a la fecha de inicio.');

                        return;
                    }

                    $maxDias = (int) config('prestamos.duracion_maxima_dias', 7);
                    if ($devolucion->greaterThan($inicio->copy()->addDays($maxDias))) {
                        $fail("La fecha de devolución estimada no puede superar los {$maxDias} días posteriores a la fecha de inicio.");
                    }
                },
            ],
        ];
    }

    /**
     * Entrega los datos validados con las fechas normalizadas a instancias Carbon en UTC.
     *
     * @param  array<string>|int|string|null  $key
     * @return array<string, mixed>|mixed
     */
    public function validated($key = null, $default = null): mixed
    {
        $validated = parent::validated($key, $default);

        if (is_array($validated)) {
            if (isset($validated['fecha_inicio']) && is_string($validated['fecha_inicio'])) {
                $validated['fecha_inicio'] = FechaNegocio::parsear($validated['fecha_inicio']);
            }

            if (isset($validated['fecha_devolucion_estimada']) && is_string($validated['fecha_devolucion_estimada'])) {
                $validated['fecha_devolucion_estimada'] = FechaNegocio::parsear($validated['fecha_devolucion_estimada']);
            }
        }

        return $validated;
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
            'fecha_devolucion_estimada.required' => 'La fecha de devolución estimada es obligatoria.',
        ];
    }
}

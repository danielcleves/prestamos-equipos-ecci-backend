<?php

namespace App\Http\Requests\Api;

use App\Enums\CondicionEquipo;
use App\Models\Prestamo;
use App\Support\FechaNegocio;
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
            'fecha_devolucion_real' => [
                'nullable',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (! FechaNegocio::esValida($value)) {
                        $fail('La fecha de devolución real debe tener un formato válido (Y-m-d H:i, Y-m-d H:i:s, o ISO 8601 con o sin desplazamiento).');

                        return;
                    }

                    $fechaDevolucion = FechaNegocio::parsear($value);
                    if ($fechaDevolucion->isAfter(now()->addMinute())) {
                        $fail('La fecha de devolución real no puede ser posterior al momento actual.');

                        return;
                    }

                    $prestamo = $this->route('prestamo');
                    if (! $prestamo instanceof Prestamo && $prestamo) {
                        $prestamo = Prestamo::find($prestamo);
                    }

                    if ($prestamo instanceof Prestamo && $prestamo->fecha_entrega_real && $fechaDevolucion->isBefore($prestamo->fecha_entrega_real)) {
                        $fail('La fecha de devolución real no puede ser anterior a la fecha de entrega real.');
                    }
                },
            ],
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
     * Entrega los datos validados con fecha_devolucion_real normalizada a Carbon en UTC.
     *
     * @param  array<string>|int|string|null  $key
     * @return array<string, mixed>|mixed
     */
    public function validated($key = null, $default = null): mixed
    {
        $validated = parent::validated($key, $default);

        if (is_array($validated) && isset($validated['fecha_devolucion_real']) && is_string($validated['fecha_devolucion_real'])) {
            $validated['fecha_devolucion_real'] = FechaNegocio::parsear($validated['fecha_devolucion_real']);
        }

        return $validated;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'condicion_devolucion.required' => 'La condición de devolución es obligatoria.',
            'condicion_devolucion.enum' => 'La condición de devolución seleccionada es inválida.',
            'observaciones.required' => 'Las observaciones son obligatorias cuando el equipo no se devuelve en buen estado.',
            'observaciones.max' => 'Las observaciones no pueden superar los 2000 caracteres.',
        ];
    }
}

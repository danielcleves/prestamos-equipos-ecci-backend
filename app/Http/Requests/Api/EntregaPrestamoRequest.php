<?php

namespace App\Http\Requests\Api;

use App\Enums\CondicionEquipo;
use App\Models\Prestamo;
use App\Support\FechaNegocio;
use Closure;
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
            'fecha_entrega_real' => [
                'nullable',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (! FechaNegocio::esValida($value)) {
                        $fail('La fecha de entrega real debe tener un formato válido (Y-m-d H:i, Y-m-d H:i:s, o ISO 8601 con o sin desplazamiento).');

                        return;
                    }

                    $fechaEntrega = FechaNegocio::parsear($value);
                    if ($fechaEntrega->isAfter(now()->addMinute())) {
                        $fail('La fecha de entrega real no puede ser posterior al momento actual.');

                        return;
                    }

                    $prestamo = $this->route('prestamo');
                    if (! $prestamo instanceof Prestamo && $prestamo) {
                        $prestamo = Prestamo::find($prestamo);
                    }

                    if ($prestamo instanceof Prestamo && $prestamo->fecha_aprobacion && $fechaEntrega->isBefore($prestamo->fecha_aprobacion)) {
                        $fail('La fecha de entrega real no puede ser anterior a la fecha de aprobación del préstamo.');
                    }
                },
            ],
            'condicion_entrega' => ['nullable', Rule::enum(CondicionEquipo::class)],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Entrega los datos validados con fecha_entrega_real normalizada a Carbon en UTC.
     *
     * @param  array<string>|int|string|null  $key
     * @return array<string, mixed>|mixed
     */
    public function validated($key = null, $default = null): mixed
    {
        $validated = parent::validated($key, $default);

        if (is_array($validated) && isset($validated['fecha_entrega_real']) && is_string($validated['fecha_entrega_real'])) {
            $validated['fecha_entrega_real'] = FechaNegocio::parsear($validated['fecha_entrega_real']);
        }

        return $validated;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'condicion_entrega.enum' => 'La condición de entrega seleccionada es inválida.',
            'observaciones.max' => 'Las observaciones no pueden superar los 2000 caracteres.',
        ];
    }
}

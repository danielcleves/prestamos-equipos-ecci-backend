<?php

namespace App\Http\Requests\Api;

use App\Models\Rol;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * POST /api/roles — crea un rol de solicitante (RN-02).
 *
 * El nombre técnico se deriva de la etiqueta («Coordinador académico» →
 * «coordinador_academico») y no cambia después: así nunca choca con los
 * nombres fijados en las rutas (admin, encargado).
 */
class StoreRolRequest extends FormRequest
{
    public function authorize(): bool
    {
        // La autorización (solo admin) la resuelve el middleware 'role:admin'.
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('etiqueta'))) {
            $this->merge(['etiqueta' => trim(preg_replace('/\s+/u', ' ', $this->input('etiqueta')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'etiqueta' => ['required', 'string', 'min:3', 'max:60'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'cupo_prestamos' => ['required', 'integer', 'min:0', 'max:'.Rol::CUPO_MAXIMO],
            'es_predeterminado' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('etiqueta')) {
                return;
            }

            $nombre = $this->nombre();

            if ($nombre === '') {
                $validator->errors()->add('etiqueta', 'La etiqueta debe contener letras o números.');

                return;
            }

            if (in_array($nombre, ['usuario', ...Rol::SISTEMA], true)
                || Rol::where('name', $nombre)->exists()
                || Rol::whereRaw('LOWER(etiqueta) = ?', [mb_strtolower($this->input('etiqueta'))])->exists()) {
                $validator->errors()->add('etiqueta', 'Ya existe un rol con ese nombre.');
            }
        });
    }

    /**
     * Nombre técnico derivado de la etiqueta.
     */
    public function nombre(): string
    {
        return Str::limit(Str::slug((string) $this->input('etiqueta'), '_'), 60, '');
    }

    public function messages(): array
    {
        return [
            'etiqueta.required' => 'El nombre del rol es obligatorio.',
            'etiqueta.min' => 'El nombre del rol debe tener al menos 3 caracteres.',
            'cupo_prestamos.required' => 'El cupo de préstamos es obligatorio.',
            'cupo_prestamos.integer' => 'El cupo debe ser un número entero.',
            'cupo_prestamos.min' => 'El cupo no puede ser negativo.',
            'cupo_prestamos.max' => 'El cupo no puede superar '.Rol::CUPO_MAXIMO.'.',
        ];
    }
}

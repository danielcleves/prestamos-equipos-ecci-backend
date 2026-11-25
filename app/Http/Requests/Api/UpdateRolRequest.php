<?php

namespace App\Http\Requests\Api;

use App\Models\Rol;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH/PUT /api/roles/{rol} — edita etiqueta, descripción, cupo y la marca
 * de predeterminado. El nombre técnico no se cambia.
 */
class UpdateRolRequest extends FormRequest
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
            'etiqueta' => ['sometimes', 'required', 'string', 'min:3', 'max:60'],
            'descripcion' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cupo_prestamos' => ['sometimes', 'required', 'integer', 'min:0', 'max:'.Rol::CUPO_MAXIMO],
            'es_predeterminado' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var Rol $rol */
            $rol = $this->route('rol');

            if ($this->has('etiqueta') && ! $validator->errors()->has('etiqueta')
                && Rol::whereKeyNot($rol->id)
                    ->whereRaw('LOWER(etiqueta) = ?', [mb_strtolower((string) $this->input('etiqueta'))])
                    ->exists()) {
                $validator->errors()->add('etiqueta', 'Ya existe un rol con ese nombre.');
            }

            if (! $this->has('es_predeterminado') || $validator->errors()->has('es_predeterminado')) {
                return;
            }

            $marcar = $this->boolean('es_predeterminado');

            if ($marcar && $rol->esSistema()) {
                $validator->errors()->add('es_predeterminado', 'Un rol de sistema no puede ser el predeterminado del registro.');
            }

            if (! $marcar && $rol->es_predeterminado) {
                $validator->errors()->add('es_predeterminado', 'Debe haber un rol predeterminado: marca otro rol en su lugar.');
            }
        });
    }

    public function messages(): array
    {
        return (new StoreRolRequest)->messages();
    }
}

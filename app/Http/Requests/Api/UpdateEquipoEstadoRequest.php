<?php

namespace App\Http\Requests\Api;

use App\Models\Equipo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEquipoEstadoRequest extends FormRequest
{
    public function authorize(): bool
    {
        // La autorizacion (solo admin) la resuelve el middleware 'role:admin'
        // de la ruta.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'estado' => ['required', 'string', Rule::in(Equipo::ESTADOS)],
        ];
    }
}

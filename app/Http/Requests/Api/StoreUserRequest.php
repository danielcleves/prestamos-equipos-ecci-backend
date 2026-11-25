<?php

namespace App\Http\Requests\Api;

use App\Models\Rol;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'string', Rule::exists('roles', 'name')],
            // RN-01: requisito de capacitación (por defecto, capacitado).
            'capacitado' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Un cliente con la lista de roles desactualizada (p. ej. una pestaña
     * abierta desde antes de un despliegue) ve qué nombres acepta el backend.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $disponibles = Rol::nombresDisponibles();

        return [
            'role.exists' => $disponibles === []
                ? 'El rol «:input» no existe.'
                : 'El rol «:input» no existe. Roles disponibles: '.implode(', ', $disponibles).'.',
        ];
    }
}

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
            // RN-01: ids de las categorías en las que queda capacitado
            // (por defecto, ninguna).
            'capacitaciones' => ['sometimes', 'array'],
            'capacitaciones.*' => ['integer', 'distinct', 'exists:categorias,id'],
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
            ...self::mensajesCapacitaciones(),
        ];
    }

    /**
     * Mensajes de 'capacitaciones' (RN-01), compartidos con UpdateUserRequest.
     *
     * @return array<string, string>
     */
    public static function mensajesCapacitaciones(): array
    {
        return [
            'capacitaciones.array' => 'Las capacitaciones deben ser una lista de ids de categoría.',
            'capacitaciones.*.integer' => 'Cada capacitación debe ser el id numérico de una categoría.',
            'capacitaciones.*.distinct' => 'La categoría «:input» está repetida en las capacitaciones.',
            'capacitaciones.*.exists' => 'La categoría «:input» no existe.',
        ];
    }
}

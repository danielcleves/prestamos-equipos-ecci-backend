<?php

namespace App\Http\Requests\Api;

use App\Services\Parametros;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Auto-registro de solicitantes.
 *
 * Solo se permiten correos exactos o dominios configurados en
 * `registro.correos_permitidos`. El rol siempre es `usuario` (solicitante);
 * el administrador puede cambiarlo después.
 */
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $email = strtolower(trim((string) $this->input('email')));

            if (! $this->correoPermitido($email)) {
                $validator->errors()->add(
                    'email',
                    'Ese correo no está autorizado para registrarse. Usa tu correo institucional o contacta al administrador.'
                );
            }
        });
    }

    private function correoPermitido(string $email): bool
    {
        $permitidos = app(Parametros::class)->correosPermitidos();

        if ($permitidos === []) {
            return false;
        }

        foreach ($permitidos as $permitido) {
            $permitido = strtolower(trim($permitido));

            if ($permitido === '') {
                continue;
            }

            if (str_starts_with($permitido, '@')) {
                if (str_ends_with($email, $permitido)) {
                    return true;
                }

                continue;
            }

            if ($email === $permitido) {
                return true;
            }
        }

        return false;
    }
}

<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class TextoNoVacio implements ValidationRule
{
    public function __construct(
        protected ?string $mensaje = null
    ) {}

    /**
     * Valida que el valor no sea una cadena vacía ni contenga solo espacios en blanco.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && trim($value) === '') {
            $fail($this->mensaje ?? 'El motivo no puede estar vacío ni contener solo espacios.');
        }
    }
}

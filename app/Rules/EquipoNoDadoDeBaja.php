<?php

namespace App\Rules;

use App\Models\Equipo;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class EquipoNoDadoDeBaja implements ValidationRule
{
    public function __construct(
        protected ?Equipo $equipo
    ) {}

    /**
     * HU-04: "un equipo dado de baja no debe poder ser prestado".
     * Se cumple haciendo el estado irreversible: un equipo en
     * Equipo::ESTADO_TERMINAL no puede cambiar a ningun otro estado,
     * no solo se bloquea la transicion puntual a 'en_prestamo'.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->equipo?->estado === Equipo::ESTADO_TERMINAL) {
            $fail('Un equipo dado de baja no puede cambiar de estado.');
        }
    }
}

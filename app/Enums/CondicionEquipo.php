<?php

namespace App\Enums;

enum CondicionEquipo: string
{
    case Bueno = 'bueno';
    case ConDanos = 'con_danos';
    case RequiereMantenimiento = 'requiere_mantenimiento';

    /**
     * Etiqueta legible en español para la API.
     */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Bueno => 'Bueno',
            self::ConDanos => 'Con daños',
            self::RequiereMantenimiento => 'Requiere mantenimiento',
        };
    }
}

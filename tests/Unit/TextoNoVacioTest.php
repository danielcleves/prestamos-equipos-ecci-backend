<?php

namespace Tests\Unit;

use App\Rules\TextoNoVacio;
use PHPUnit\Framework\TestCase;

class TextoNoVacioTest extends TestCase
{
    public function test_acepta_texto_valido(): void
    {
        $regla = new TextoNoVacio;
        $fallo = false;

        $regla->validate('motivo', 'Texto válido de prueba', function () use (&$fallo) {
            $fallo = true;
        });

        $this->assertFalse($fallo);
    }

    public function test_falla_con_cadena_vacia(): void
    {
        $regla = new TextoNoVacio;
        $mensajeFallo = null;

        $regla->validate('motivo', '', function ($mensaje) use (&$mensajeFallo) {
            $mensajeFallo = $mensaje;
        });

        $this->assertSame('El motivo no puede estar vacío ni contener solo espacios.', $mensajeFallo);
    }

    public function test_falla_con_solo_espacios_en_blanco(): void
    {
        $regla = new TextoNoVacio;
        $mensajeFallo = null;

        $regla->validate('motivo', "   \n\t  ", function ($mensaje) use (&$mensajeFallo) {
            $mensajeFallo = $mensaje;
        });

        $this->assertSame('El motivo no puede estar vacío ni contener solo espacios.', $mensajeFallo);
    }

    public function test_permite_personalizar_el_mensaje_de_error(): void
    {
        $regla = new TextoNoVacio('El motivo del rechazo es obligatorio y no puede estar vacío.');
        $mensajeFallo = null;

        $regla->validate('motivo', '   ', function ($mensaje) use (&$mensajeFallo) {
            $mensajeFallo = $mensaje;
        });

        $this->assertSame('El motivo del rechazo es obligatorio y no puede estar vacío.', $mensajeFallo);
    }
}

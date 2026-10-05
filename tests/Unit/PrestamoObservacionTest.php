<?php

namespace Tests\Unit;

use App\Models\Prestamo;
use PHPUnit\Framework\TestCase;

class PrestamoObservacionTest extends TestCase
{
    public function test_agrega_primera_observacion_con_prefijo(): void
    {
        $prestamo = new Prestamo;
        $prestamo->agregarObservacion('entrega', 'Equipo entregado con cargador original');

        $this->assertSame('Entrega: Equipo entregado con cargador original', $prestamo->observaciones);
    }

    public function test_acumula_segunda_observacion_en_linea_nueva_sin_sobrescribir(): void
    {
        $prestamo = new Prestamo;
        $prestamo->agregarObservacion('entrega', 'Equipo entregado en buenas condiciones');
        $prestamo->agregarObservacion('devolución', 'Devuelto con pantalla rayada');

        $esperado = "Entrega: Equipo entregado en buenas condiciones\nDevolución: Devuelto con pantalla rayada";
        $this->assertSame($esperado, $prestamo->observaciones);
    }

    public function test_ignora_textos_nulos_o_en_blanco(): void
    {
        $prestamo = new Prestamo;
        $prestamo->agregarObservacion('entrega', null);
        $this->assertNull($prestamo->observaciones);

        $prestamo->agregarObservacion('entrega', '   ');
        $this->assertNull($prestamo->observaciones);

        $prestamo->agregarObservacion('entrega', 'Nota inicial');
        $prestamo->agregarObservacion('devolución', '');
        $this->assertSame('Entrega: Nota inicial', $prestamo->observaciones);
    }
}

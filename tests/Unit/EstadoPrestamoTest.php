<?php

namespace Tests\Unit;

use App\Enums\EstadoPrestamo;
use PHPUnit\Framework\TestCase;

class EstadoPrestamoTest extends TestCase
{
    public function test_transiciones_validas_desde_solicitado(): void
    {
        $solicitado = EstadoPrestamo::Solicitado;

        $this->assertTrue($solicitado->puedeTransicionarA(EstadoPrestamo::Aprobado));
        $this->assertTrue($solicitado->puedeTransicionarA(EstadoPrestamo::Rechazado));
        $this->assertTrue($solicitado->puedeTransicionarA(EstadoPrestamo::Cancelado));

        $this->assertFalse($solicitado->puedeTransicionarA(EstadoPrestamo::Entregado));
        $this->assertFalse($solicitado->puedeTransicionarA(EstadoPrestamo::Devuelto));
        $this->assertFalse($solicitado->puedeTransicionarA(EstadoPrestamo::Solicitado));
    }

    public function test_transiciones_validas_desde_aprobado(): void
    {
        $aprobado = EstadoPrestamo::Aprobado;

        $this->assertTrue($aprobado->puedeTransicionarA(EstadoPrestamo::Entregado));
        $this->assertTrue($aprobado->puedeTransicionarA(EstadoPrestamo::Cancelado));

        $this->assertFalse($aprobado->puedeTransicionarA(EstadoPrestamo::Solicitado));
        $this->assertFalse($aprobado->puedeTransicionarA(EstadoPrestamo::Rechazado));
        $this->assertFalse($aprobado->puedeTransicionarA(EstadoPrestamo::Devuelto));
    }

    public function test_transiciones_validas_desde_entregado(): void
    {
        $entregado = EstadoPrestamo::Entregado;

        $this->assertTrue($entregado->puedeTransicionarA(EstadoPrestamo::Devuelto));

        $this->assertFalse($entregado->puedeTransicionarA(EstadoPrestamo::Solicitado));
        $this->assertFalse($entregado->puedeTransicionarA(EstadoPrestamo::Aprobado));
        $this->assertFalse($entregado->puedeTransicionarA(EstadoPrestamo::Rechazado));
        $this->assertFalse($entregado->puedeTransicionarA(EstadoPrestamo::Cancelado));
    }

    public function test_estados_finales_no_tienen_transiciones_salientes(): void
    {
        foreach ([EstadoPrestamo::Rechazado, EstadoPrestamo::Cancelado, EstadoPrestamo::Devuelto] as $estadoFinal) {
            $this->assertTrue($estadoFinal->isFinal());
            $this->assertEmpty($estadoFinal->transicionesValidas());

            foreach (EstadoPrestamo::cases() as $cualquierDestino) {
                $this->assertFalse($estadoFinal->puedeTransicionarA($cualquierDestino));
            }
        }
    }

    public function test_todos_los_estados_poseen_etiqueta_legible_en_espanol(): void
    {
        foreach (EstadoPrestamo::cases() as $estado) {
            $this->assertNotEmpty($estado->etiqueta());
            $this->assertIsString($estado->etiqueta());
        }
    }
}

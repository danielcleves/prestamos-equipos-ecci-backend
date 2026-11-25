<?php

namespace Tests\Unit;

use App\Support\FechaNegocio;
use Carbon\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class FechaNegocioTest extends TestCase
{
    public function test_parsear_formato_espacio_sin_segundos_interpreta_como_hora_de_bogota(): void
    {
        $carbon = FechaNegocio::parsear('2026-10-15 08:00');

        $this->assertNotNull($carbon);
        $this->assertSame('UTC', $carbon->timezoneName);
        $this->assertSame('2026-10-15 13:00:00', $carbon->format('Y-m-d H:i:s'));
    }

    public function test_parsear_formato_espacio_con_segundos_interpreta_como_hora_de_bogota(): void
    {
        $carbon = FechaNegocio::parsear('2026-10-15 08:00:30');

        $this->assertNotNull($carbon);
        $this->assertSame('UTC', $carbon->timezoneName);
        $this->assertSame('2026-10-15 13:00:30', $carbon->format('Y-m-d H:i:s'));
    }

    public function test_parsear_formato_iso_sin_desplazamiento_sin_segundos_interpreta_como_hora_de_bogota(): void
    {
        $carbon = FechaNegocio::parsear('2026-10-15T08:00');

        $this->assertNotNull($carbon);
        $this->assertSame('UTC', $carbon->timezoneName);
        $this->assertSame('2026-10-15 13:00:00', $carbon->format('Y-m-d H:i:s'));
    }

    public function test_parsear_formato_iso_sin_desplazamiento_con_segundos_interpreta_como_hora_de_bogota(): void
    {
        $carbon = FechaNegocio::parsear('2026-10-15T08:00:45');

        $this->assertNotNull($carbon);
        $this->assertSame('UTC', $carbon->timezoneName);
        $this->assertSame('2026-10-15 13:00:45', $carbon->format('Y-m-d H:i:s'));
    }

    public function test_parsear_iso8601_con_desplazamiento_explicito(): void
    {
        $carbon = FechaNegocio::parsear('2026-10-15T08:00:00-05:00');

        $this->assertNotNull($carbon);
        $this->assertSame('UTC', $carbon->timezoneName);
        $this->assertSame('2026-10-15 13:00:00', $carbon->format('Y-m-d H:i:s'));
    }

    public function test_parsear_iso8601_con_desplazamiento_sin_segundos(): void
    {
        $this->assertTrue(FechaNegocio::esValida('2026-10-15T08:00-05:00'));

        $carbon = FechaNegocio::parsear('2026-10-15T08:00-05:00');

        $this->assertNotNull($carbon);
        $this->assertSame('UTC', $carbon->timezoneName);
        $this->assertSame('2026-10-15 13:00:00', $carbon->format('Y-m-d H:i:s'));
    }

    public function test_parsear_iso8601_con_z(): void
    {
        $carbon = FechaNegocio::parsear('2026-10-15T13:00:00Z');

        $this->assertNotNull($carbon);
        $this->assertSame('UTC', $carbon->timezoneName);
        $this->assertSame('2026-10-15 13:00:00', $carbon->format('Y-m-d H:i:s'));
    }

    public function test_parsear_iso8601_con_z_sin_segundos(): void
    {
        $this->assertTrue(FechaNegocio::esValida('2026-10-15T08:00Z'));

        $carbon = FechaNegocio::parsear('2026-10-15T08:00Z');

        $this->assertNotNull($carbon);
        $this->assertSame('UTC', $carbon->timezoneName);
        $this->assertSame('2026-10-15 08:00:00', $carbon->format('Y-m-d H:i:s'));
    }

    public function test_parsear_iso8601_con_fraccion_de_segundos_y_z(): void
    {
        $carbon = FechaNegocio::parsear('2026-10-15T13:00:00.000000Z');

        $this->assertNotNull($carbon);
        $this->assertSame('UTC', $carbon->timezoneName);
        $this->assertSame('2026-10-15 13:00:00', $carbon->format('Y-m-d H:i:s'));
    }

    public function test_rechaza_fecha_sin_hora(): void
    {
        $this->assertFalse(FechaNegocio::esValida('2026-10-15'));

        $this->expectException(InvalidArgumentException::class);
        FechaNegocio::parsear('2026-10-15');
    }

    public function test_rechaza_cadenas_relativas(): void
    {
        $this->assertFalse(FechaNegocio::esValida('tomorrow'));

        $this->expectException(InvalidArgumentException::class);
        FechaNegocio::parsear('tomorrow');
    }

    public function test_rechaza_formato_con_barras(): void
    {
        $this->assertFalse(FechaNegocio::esValida('15/10/2026 08:00'));

        $this->expectException(InvalidArgumentException::class);
        FechaNegocio::parsear('15/10/2026 08:00');
    }

    public function test_rechaza_fechas_calendario_invalidas(): void
    {
        $this->assertFalse(FechaNegocio::esValida('2026-02-30 08:00'));
        $this->assertFalse(FechaNegocio::esValida('2026-02-30T08:00:00Z'));

        $this->expectException(InvalidArgumentException::class);
        FechaNegocio::parsear('2026-02-30 08:00');
    }

    public function test_parsear_nulo_o_vacio_retorna_null(): void
    {
        $this->assertNull(FechaNegocio::parsear(null));
        $this->assertNull(FechaNegocio::parsear(''));
        $this->assertNull(FechaNegocio::parsear('   '));
    }

    public function test_formatear_convierte_a_zona_de_negocio_con_desplazamiento_menos_cinco(): void
    {
        $utc = Carbon::parse('2026-10-15 13:00:00', 'UTC');

        $formateado = FechaNegocio::formatear($utc);

        $this->assertSame('2026-10-15T08:00:00-05:00', $formateado);
    }

    public function test_formatear_no_muta_la_instancia_original(): void
    {
        $utc = Carbon::parse('2026-10-15 13:00:00', 'UTC');

        FechaNegocio::formatear($utc);

        $this->assertSame('UTC', $utc->timezoneName);
        $this->assertSame('2026-10-15 13:00:00', $utc->format('Y-m-d H:i:s'));
    }

    public function test_formatear_nulo_retorna_null(): void
    {
        $this->assertNull(FechaNegocio::formatear(null));
    }
}

<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use InvalidArgumentException;

class FechaNegocio
{
    /**
     * Patrones estrictos permitidos:
     * 1. Sin desplazamiento (se interpretan en la zona horaria de negocio):
     *    - Y-m-d H:i
     *    - Y-m-d H:i:s
     *    - Y-m-d\TH:i
     *    - Y-m-d\TH:i:s
     * 2. ISO 8601 con desplazamiento o Z (con o sin segundos, con o sin fracción de segundos).
     */
    private const PATRON_SIN_DESPLAZAMIENTO = '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2})?$/';

    private const PATRON_CON_DESPLAZAMIENTO = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:\.\d+)?(?:Z|[+-]\d{2}(?::?\d{2})?)$/';

    /**
     * Retorna el identificador de la zona horaria de negocio (por defecto America/Bogota).
     */
    public static function zonaNegocio(): string
    {
        return (string) config('prestamos.zona_horaria', 'America/Bogota');
    }

    /**
     * Mensaje de error en español para formatos de fecha inválidos.
     */
    public static function mensajeFormato(string $nombreCampo = 'fecha'): string
    {
        return "El campo {$nombreCampo} debe tener un formato de fecha válido (Y-m-d H:i, Y-m-d H:i:s, o ISO 8601 con o sin desplazamiento).";
    }

    /**
     * Determina si un valor cumple estrictamente con los formatos de fecha permitidos y es una fecha de calendario válida.
     */
    public static function esValida(mixed $valor): bool
    {
        if (! is_string($valor) || trim($valor) === '') {
            return false;
        }

        $valor = trim($valor);

        // 1. Formatos sin desplazamiento
        if (preg_match(self::PATRON_SIN_DESPLAZAMIENTO, $valor)) {
            $formato = self::detectarFormatoSinDesplazamiento($valor);
            if (! $formato) {
                return false;
            }

            try {
                $carbon = Carbon::createFromFormat("!{$formato}", $valor, self::zonaNegocio());
                $errores = Carbon::getLastErrors();

                return $carbon !== false && empty($errores['warning_count']) && empty($errores['error_count']);
            } catch (\Throwable) {
                return false;
            }
        }

        // 2. Formatos ISO 8601 con desplazamiento o Z
        if (preg_match(self::PATRON_CON_DESPLAZAMIENTO, $valor)) {
            try {
                // Comprobar validez de calendario del día, mes y año
                $partes = explode('T', $valor)[0];
                [$anio, $mes, $dia] = explode('-', $partes);

                if (! checkdate((int) $mes, (int) $dia, (int) $anio)) {
                    return false;
                }

                Carbon::parse($valor);

                return true;
            } catch (\Throwable) {
                return false;
            }
        }

        return false;
    }

    /**
     * Parsea una cadena de fecha a una instancia Carbon normalizada en UTC.
     * Las fechas sin desplazamiento se interpretan en la zona horaria de negocio antes de convertirse a UTC.
     *
     * @throws InvalidArgumentException
     */
    public static function parsear(?string $valor): ?Carbon
    {
        if ($valor === null || trim($valor) === '') {
            return null;
        }

        $valor = trim($valor);

        if (! self::esValida($valor)) {
            throw new InvalidArgumentException(self::mensajeFormato());
        }

        if (preg_match(self::PATRON_SIN_DESPLAZAMIENTO, $valor)) {
            $formato = self::detectarFormatoSinDesplazamiento($valor);
            /** @var Carbon $carbon */
            $carbon = Carbon::createFromFormat("!{$formato}", $valor, self::zonaNegocio());

            return $carbon->setTimezone('UTC');
        }

        return Carbon::parse($valor)->setTimezone('UTC');
    }

    /**
     * Formatea una fecha para serialización en resources hacia la zona de negocio.
     * Utiliza una copia para no mutar el atributo del modelo en memoria.
     */
    public static function formatear(?CarbonInterface $fecha): ?string
    {
        if ($fecha === null) {
            return null;
        }

        return $fecha->copy()->setTimezone(self::zonaNegocio())->toIso8601String();
    }

    /**
     * Detecta el formato exacto para cadenas sin desplazamiento.
     */
    private static function detectarFormatoSinDesplazamiento(string $valor): ?string
    {
        if (str_contains($valor, 'T')) {
            return strlen($valor) === 16 ? 'Y-m-d\TH:i' : 'Y-m-d\TH:i:s';
        }

        if (str_contains($valor, ' ')) {
            return strlen($valor) === 16 ? 'Y-m-d H:i' : 'Y-m-d H:i:s';
        }

        return null;
    }
}

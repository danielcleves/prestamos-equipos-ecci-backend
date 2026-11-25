<?php

namespace App\Enums;

enum EstadoPrestamo: string
{
    case Solicitado = 'solicitado';
    case Aprobado = 'aprobado';
    case Rechazado = 'rechazado';
    case Cancelado = 'cancelado';
    case Entregado = 'entregado';
    case Devuelto = 'devuelto';

    /**
     * Etiqueta legible en español para la API.
     */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Solicitado => 'Solicitado',
            self::Aprobado => 'Aprobado',
            self::Rechazado => 'Rechazado',
            self::Cancelado => 'Cancelado',
            self::Entregado => 'Entregado',
            self::Devuelto => 'Devuelto',
        };
    }

    /**
     * Estados que cuentan como préstamo "activo": el préstamo sigue vivo y
     * compromete al solicitante y al equipo.
     *
     * Fuente única de la definición. La consumen el cupo
     * `max_activos_por_usuario` (SolicitudPrestamoService) y el filtro
     * ?activos= del listado (HU-10), para que no puedan divergir.
     *
     * Rechazado, cancelado y devuelto NUNCA son activos: son estados finales
     * que liberan cupo y dejan el equipo libre.
     *
     * Ojo: no confundir con GET /prestamos/activos (HU-11), que es más
     * estrecho y lista solo los 'entregado', es decir los que están
     * físicamente en manos de alguien.
     *
     * @return array<self>
     */
    public static function activos(): array
    {
        return [
            self::Solicitado,
            self::Aprobado,
            self::Entregado,
        ];
    }

    /**
     * Mismos estados que activos(), como valores de cadena para consultas.
     *
     * @return array<string>
     */
    public static function valoresActivos(): array
    {
        return array_map(fn (self $estado) => $estado->value, self::activos());
    }

    /**
     * Determina si este estado cuenta como préstamo activo.
     */
    public function esActivo(): bool
    {
        return in_array($this, self::activos(), true);
    }

    /**
     * Mapa explícito de transiciones válidas centralizado en un solo lugar.
     * Facilita incorporar futuros estados (ej. con retraso) sin reescribir la lógica.
     *
     * @return array<self>
     */
    public function transicionesValidas(): array
    {
        return match ($this) {
            self::Solicitado => [
                self::Aprobado,
                self::Rechazado,
                self::Cancelado,
            ],
            self::Aprobado => [
                self::Entregado,
                self::Cancelado,
            ],
            self::Entregado => [
                self::Devuelto,
            ],
            self::Rechazado,
            self::Cancelado,
            self::Devuelto => [],
        };
    }

    /**
     * Determina si es posible transicionar desde el estado actual al destino especificado.
     */
    public function puedeTransicionarA(self $destino): bool
    {
        return in_array($destino, $this->transicionesValidas(), true);
    }

    /**
     * Mensaje estándar para una transición no permitida.
     * Centralizado aquí para que cualquier punto que valide la transición
     * antes de ejecutarla (ej. el orquestador de la devolución en HU-12)
     * produzca exactamente el mismo mensaje que TransicionPrestamoService.
     */
    public function mensajeTransicionInvalida(self $destino): string
    {
        return "El préstamo está en estado {$this->etiqueta()} y no puede pasar a {$destino->etiqueta()}.";
    }

    /**
     * Determina si el estado actual es un estado final.
     */
    public function isFinal(): bool
    {
        return empty($this->transicionesValidas());
    }
}

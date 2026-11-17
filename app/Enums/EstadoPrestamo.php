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

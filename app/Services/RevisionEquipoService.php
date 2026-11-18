<?php

namespace App\Services;

use App\Enums\CondicionEquipo;
use App\Enums\EstadoPrestamo;
use App\Models\Prestamo;
use App\Models\RevisionEquipo;
use App\Models\User;
use App\Support\FechaNegocio;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HU-12 / KAN-126: registro de la revision del estado del equipo al momento
 * de la devolucion.
 *
 * El servicio es la unica puerta de entrada para crear una revision. Lo
 * invoca DevolucionPrestamoService dentro de su propia transaccion, de modo
 * que la revision y la devolucion son atomicas: si una falla, ninguna queda
 * registrada.
 */
class RevisionEquipoService
{
    /**
     * Registra la revisión de un prestamo en proceso de devolucion.
     *
     * Se llama ANTES de que TransicionPrestamoService mueva el prestamo a
     * 'devuelto', por eso el estado esperado es 'entregado'.
     *
     * @param  array{
     *     condicion: CondicionEquipo|string,
     *     observaciones?: string|null,
     *     fecha_revision?: CarbonInterface|string|null
     * }  $datos
     * @param  Prestamo|null  $bloqueado  Fila del prestamo que el llamador ya
     *                                    bloqueo con lockForUpdate() dentro de
     *                                    su transaccion. Cuando se pasa, se usa
     *                                    tal cual y se evita un segundo SELECT
     *                                    ... FOR UPDATE de la misma fila.
     *
     * @throws ValidationException
     */
    public function registrar(Prestamo $prestamo, User $personal, array $datos, ?Prestamo $bloqueado = null): RevisionEquipo
    {
        return DB::transaction(function () use ($prestamo, $personal, $datos, $bloqueado) {
            // El actor debe ser personal administrativo (admin o encargado).
            // Las rutas ya lo filtran con el middleware 'role:admin|encargado',
            // pero el servicio no asume que siempre se le llame desde HTTP.
            if (! $personal->esPersonalAdministrativo()) {
                throw ValidationException::withMessages([
                    'revisado_por' => 'Solo el personal de préstamo puede registrar la revisión del equipo.',
                ]);
            }

            // Orden de bloqueo unico del modulo: usuario -> equipo -> prestamo.
            // El equipo ya viene bloqueado por el orquestador (la devolucion);
            // aqui solo se recarga el prestamo en ultimo lugar.
            // Nota: lockForUpdate() no bloquea en SQLite (las carreras de
            // concurrencia solo se reproducen en MySQL 8.0).
            $prestamoFresco = $bloqueado
                ?? Prestamo::whereKey($prestamo->getKey())->lockForUpdate()->first();

            if ($prestamoFresco === null) {
                throw ValidationException::withMessages([
                    'prestamo_id' => 'El préstamo indicado no existe.',
                ]);
            }

            // Solo un prestamo entregado puede estar en proceso de devolucion.
            if ($prestamoFresco->estado !== EstadoPrestamo::Entregado) {
                throw ValidationException::withMessages([
                    'estado' => "El préstamo está en estado {$prestamoFresco->estado->etiqueta()} y no se le puede registrar la revisión de devolución.",
                ]);
            }

            // Una sola revision por prestamo: se valida antes de insertar para
            // devolver un 422 en español en vez de un error de base de datos.
            if (RevisionEquipo::where('prestamo_id', $prestamoFresco->id)->exists()) {
                throw ValidationException::withMessages([
                    'prestamo_id' => 'El préstamo ya cuenta con una revisión registrada.',
                ]);
            }

            $condicion = $this->normalizarCondicion($datos['condicion'] ?? null);

            $observaciones = isset($datos['observaciones']) && trim((string) $datos['observaciones']) !== ''
                ? trim((string) $datos['observaciones'])
                : null;

            // Si hay novedad, hay que decir cual. Misma regla que aplica
            // DevolucionPrestamoRequest sobre el payload de la peticion.
            $tieneDanos = RevisionEquipo::condicionTieneDanos($condicion);

            if ($tieneDanos && $observaciones === null) {
                throw ValidationException::withMessages([
                    'observaciones' => 'Las observaciones son obligatorias cuando el equipo no se devuelve en buen estado.',
                ]);
            }

            $fechaRevision = $this->normalizarFecha($datos['fecha_revision'] ?? null);

            return RevisionEquipo::create([
                'prestamo_id' => $prestamoFresco->id,
                'equipo_id' => $prestamoFresco->equipo_id,
                'condicion' => $condicion,
                'tiene_danos' => $tieneDanos,
                'observaciones' => $observaciones,
                'revisado_por' => $personal->id,
                'fecha_revision' => $fechaRevision,
            ]);
        });
    }

    /**
     * Normaliza la condicion recibida a una instancia del enum.
     *
     * @throws ValidationException
     */
    private function normalizarCondicion(mixed $condicion): CondicionEquipo
    {
        if ($condicion instanceof CondicionEquipo) {
            return $condicion;
        }

        if (is_string($condicion) && ($resuelta = CondicionEquipo::tryFrom($condicion)) !== null) {
            return $resuelta;
        }

        throw ValidationException::withMessages([
            'condicion' => 'La condición del equipo en la revisión es obligatoria y debe ser válida.',
        ]);
    }

    /**
     * Normaliza la fecha de revision a Carbon en UTC, con now() por defecto.
     *
     * @throws ValidationException
     */
    private function normalizarFecha(mixed $fecha): CarbonInterface
    {
        if ($fecha === null || (is_string($fecha) && trim($fecha) === '')) {
            return now();
        }

        if ($fecha instanceof CarbonInterface) {
            return $fecha;
        }

        try {
            $parseada = FechaNegocio::parsear((string) $fecha);
        } catch (\InvalidArgumentException) {
            $parseada = null;
        }

        if ($parseada === null) {
            throw ValidationException::withMessages([
                'fecha_revision' => FechaNegocio::mensajeFormato('fecha de revisión'),
            ]);
        }

        return $parseada;
    }
}

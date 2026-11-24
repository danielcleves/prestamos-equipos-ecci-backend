<?php

namespace App\Services;

use App\Enums\EstadoPrestamo;
use App\Models\Equipo;
use App\Models\Incidencia;
use App\Models\Prestamo;
use App\Models\Sancion;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * RN-01 — Elegibilidad para préstamo.
 *
 * Se ejecuta antes de crear una solicitud. Rechaza con 422 (campo
 * 'usuario_id', para que el front lo muestre junto al solicitante) cuando:
 *  - el usuario tiene una sanción activa y vigente (RN-04);
 *  - tiene un préstamo entregado y vencido;
 *  - hay una incidencia abierta que todavía lo restringe (RN-08);
 *  - se exige capacitación y el usuario no está capacitado;
 *  - la categoría del equipo es de nivel 2 y el usuario no es personal.
 */
class ElegibilidadService
{
    public function __construct(
        protected Parametros $parametros
    ) {}

    /**
     * @throws ValidationException
     */
    public function validar(User $usuario, Equipo $equipo): void
    {
        // Las sanciones vencidas se consideran cumplidas al consultarlas: así
        // el estado persistido no se queda en 'activa' para siempre.
        Sancion::where('user_id', $usuario->id)
            ->where('estado', Sancion::ESTADO_ACTIVA)
            ->where('fecha_fin', '<', now())
            ->update(['estado' => Sancion::ESTADO_CUMPLIDA]);

        if (Sancion::where('user_id', $usuario->id)->vigentes()->exists()) {
            throw ValidationException::withMessages([
                'usuario_id' => 'Tienes una sanción activa por mora y no puedes solicitar préstamos hasta que finalice.',
            ]);
        }

        $prestamoVencido = Prestamo::where('usuario_id', $usuario->id)
            ->where('estado', EstadoPrestamo::Entregado->value)
            ->where('fecha_devolucion_estimada', '<', now())
            ->orderBy('fecha_devolucion_estimada')
            ->first();

        if ($prestamoVencido !== null) {
            throw ValidationException::withMessages([
                'usuario_id' => 'Tienes un préstamo entregado y vencido; debes devolverlo antes de solicitar otro equipo.',
            ]);
        }

        $incidenciaVigente = Incidencia::where('user_id', $usuario->id)
            ->restringentes()
            ->first();

        if ($incidenciaVigente !== null) {
            throw ValidationException::withMessages([
                'usuario_id' => 'Tienes una incidencia abierta por daños y no puedes solicitar préstamos hasta que sea revisada.',
            ]);
        }

        if ($this->parametros->requiereCapacitacion() && ! $usuario->capacitado) {
            throw ValidationException::withMessages([
                'usuario_id' => 'Debes estar capacitado para solicitar préstamos.',
            ]);
        }

        $categoria = $equipo->categoria;

        if ($categoria !== null && $categoria->esSoloPersonal() && ! $usuario->esPersonalAdministrativo()) {
            throw ValidationException::withMessages([
                'usuario_id' => 'Este tipo de equipo solo puede ser solicitado por el personal administrativo.',
            ]);
        }
    }
}

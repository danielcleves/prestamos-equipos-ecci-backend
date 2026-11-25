<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defensa en profundidad para cuentas desactivadas.
 *
 * Al desactivar un usuario se revocan sus tokens (UserController::desactivar),
 * pero is_active solo se revisaba en el login: un token emitido antes (o uno
 * que sobreviviera por cualquier motivo, p. ej. una desactivación hecha a mano
 * en la base) seguía operando sin límite, porque sanctum.expiration = null.
 *
 * Este middleware va justo después de auth:sanctum: si la cuenta está
 * desactivada revoca el token de la petición y responde 401, que el frontend
 * ya traduce a «cerrar sesión y volver al login».
 */
class AsegurarCuentaActiva
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        // Comparación estricta contra false: un User recién creado conserva
        // is_active = null en memoria (el default true solo existe en la
        // columna), y no se debe rechazar por eso. La cuenta desactivada sí
        // llega como false desde la base (cast booleano).
        if ($usuario !== null && $usuario->is_active === false) {
            $token = $usuario->currentAccessToken();

            if ($token instanceof PersonalAccessToken) {
                $token->delete();
            }

            return response()->json([
                'message' => __('errores.cuenta_desactivada'),
            ], 401);
        }

        return $next($request);
    }
}

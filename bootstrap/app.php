<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Este backend es solo API: no sirve vistas ni tiene ruta 'login'.
        // Por defecto el middleware Authenticate resuelve route('login') para
        // redirigir a los invitados, lo que revienta con RouteNotFoundException
        // (500) antes de que la excepcion llegue al manejador. Devolviendo null
        // no hay redireccion y la peticion termina como un 401 JSON.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Sin esto, una peticion a api/* sin token y sin cabecera
        // "Accept: application/json" hace que Laravel intente redirigir a la
        // ruta 'login', que no existe en una API: el cliente recibe un 500 con
        // la traza completa en lugar de un 401.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'No autenticado.'], 401);
            }
        });
    })->create();

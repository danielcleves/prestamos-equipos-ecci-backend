<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\RoleMiddleware;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Spatie no registra el alias automaticamente en el estilo de
        // bootstrap/app.php (Laravel 11+); hay que darlo de alta a mano para
        // poder usar 'role:admin' en las rutas.
        $middleware->alias([
            'role' => RoleMiddleware::class,
        ]);

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

        // 401: AuthenticationException (sin token, token invalido o revocado)
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => __('errores.no_autenticado')], 401);
            }
        });

        // 403: AuthorizationException, AccessDeniedHttpException y Spatie UnauthorizedException
        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => __('errores.prohibido')], 403);
            }
        });

        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => __('errores.prohibido')], 403);
            }
        });

        $exceptions->render(function (UnauthorizedException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => __('errores.prohibido')], 403);
            }
        });

        // 404: ModelNotFoundException y NotFoundHttpException
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => __('errores.no_encontrado')], 404);
            }
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => __('errores.no_encontrado')], 404);
            }
        });

        // 405: MethodNotAllowedHttpException
        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => __('errores.metodo_no_permitido')], 405);
            }
        });

        // Captura general de HttpException con status 403
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($request->is('api/*') && $e->getStatusCode() === 403) {
                return response()->json(['message' => __('errores.prohibido')], 403);
            }
        });

        // 500: Cualquier excepcion no controlada, SOLO cuando app.debug es false
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                if ($e instanceof HttpResponseException || $e instanceof ValidationException) {
                    return null;
                }

                if ($e instanceof HttpException && $e->getStatusCode() < 500) {
                    return null;
                }

                if (! config('app.debug')) {
                    return response()->json(['message' => __('errores.servidor')], 500);
                }
            }
        });
    })->create();

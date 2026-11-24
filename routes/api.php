<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoriaController;
use App\Http\Controllers\Api\ConfiguracionController;
use App\Http\Controllers\Api\EquipoController;
use App\Http\Controllers\Api\IncidenciaController;
use App\Http\Controllers\Api\PrestamoController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => response()->json(['status' => 'ok']));

// throttle:6,1 limita a 6 intentos por minuto y por IP, para frenar los
// ataques de fuerza bruta contra credenciales.
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:6,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
});

// HU-02: gestion de usuarios y roles, exclusiva del rol admin.
Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::apiResource('usuarios', UserController::class)
        ->except('destroy')
        ->parameters(['usuarios' => 'usuario']);

    Route::patch('/usuarios/{usuario}/activar', [UserController::class, 'activar']);
    Route::patch('/usuarios/{usuario}/desactivar', [UserController::class, 'desactivar']);

    // Iteración 2: parámetros de negocio y nivel de restricción por categoría.
    Route::get('/configuracion', [ConfiguracionController::class, 'show']);
    Route::put('/configuracion', [ConfiguracionController::class, 'update']);
    Route::patch('/categorias/{categoria}/restriccion', [CategoriaController::class, 'actualizarRestriccion']);
});

// HU-03: catalogo de equipos. Consultar es para cualquier autenticado (lo
// va a necesitar HU-05 mas adelante); registrar es exclusivo de admin.
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/equipos', [EquipoController::class, 'index']);
    Route::get('/equipos/{equipo}', [EquipoController::class, 'show']);
    Route::get('/equipos/{equipo}/historial', [EquipoController::class, 'historial']);
});

// Escritura sobre el catalogo, exclusiva de admin: registrar (HU-03) y
// cambiar el estado (HU-04).
Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::post('/equipos', [EquipoController::class, 'store']);
    Route::patch('/equipos/{equipo}/estado', [EquipoController::class, 'actualizarEstado']);
});

// Rutas para personal administrativo (admin y encargado). Comparten
// middleware, así que van en un solo grupo:
// - HU-12: revisiones de devolución de un equipo (las observaciones son
//   reservadas, a diferencia de /historial, que ve cualquier autenticado).
// - HU-11: consulta de préstamos activos (registrada ANTES de /prestamos/{prestamo} para evitar colisión)
// - HU-08: aprobación y rechazo de solicitudes
// - HU-09: registro de entrega
// - HU-11: registro de devolución
Route::middleware(['auth:sanctum', 'role:admin|encargado'])->group(function () {
    Route::get('/equipos/{equipo}/revisiones', [EquipoController::class, 'revisiones']);
    Route::get('/prestamos/activos', [PrestamoController::class, 'activos']);
    // HU-07: solicitudes pendientes de gestion. Igual que /activos, va
    // registrada ANTES de /prestamos/{prestamo} para que 'pendientes' no se
    // interprete como un id.
    Route::get('/prestamos/pendientes', [PrestamoController::class, 'pendientes']);
    Route::post('/prestamos/{prestamo}/aprobacion', [PrestamoController::class, 'aprobacion']);
    Route::post('/prestamos/{prestamo}/rechazo', [PrestamoController::class, 'rechazo']);
    Route::post('/prestamos/{prestamo}/entrega', [PrestamoController::class, 'entrega']);
    Route::post('/prestamos/{prestamo}/devolucion', [PrestamoController::class, 'devolucion']);
    // RN-03: autorización especial previa a la aprobación.
    Route::post('/prestamos/{prestamo}/autorizacion', [PrestamoController::class, 'autorizacion']);

    // RN-08: incidencias por daños en la devolución.
    Route::get('/incidencias', [IncidenciaController::class, 'index']);
    Route::patch('/incidencias/{incidencia}/cerrar', [IncidenciaController::class, 'cerrar']);
});

// HU-06: Solicitud y consulta de préstamos (cualquier rol autenticado).
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/prestamos', [PrestamoController::class, 'index']);
    Route::post('/prestamos', [PrestamoController::class, 'store'])
        ->middleware('throttle:10,1');
    Route::get('/prestamos/{prestamo}', [PrestamoController::class, 'show']);
});

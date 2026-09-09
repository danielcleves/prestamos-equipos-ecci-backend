<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EquipoController;
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
});

// HU-03: catalogo de equipos. Consultar es para cualquier autenticado (lo
// va a necesitar HU-05 mas adelante); registrar es exclusivo de admin.
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/equipos', [EquipoController::class, 'index']);
    Route::get('/equipos/{equipo}', [EquipoController::class, 'show']);
});

Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::post('/equipos', [EquipoController::class, 'store']);
});

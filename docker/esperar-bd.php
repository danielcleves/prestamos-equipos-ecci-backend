<?php

/**
 * Comprueba si la base de datos ya acepta conexiones.
 *
 * Sale con 0 si conecta y con 1 si no. Lo usa docker/entrypoint.sh para esperar
 * a MySQL antes de migrar.
 *
 * Arranca Laravel para tomar la conexion de la configuracion (.env) y no
 * duplicar aqui host, usuario ni contrasena. No se usa "artisan db:show"
 * porque ese comando falla al formatear su salida si no esta la extension
 * intl, aunque la conexion haya funcionado.
 */

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    Illuminate\Support\Facades\DB::connection()->getPdo();

    exit(0);
} catch (Throwable $e) {
    exit(1);
}

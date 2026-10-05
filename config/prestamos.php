<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Parámetros de negocio para préstamos de equipos
    |--------------------------------------------------------------------------
    |
    | Valores leídos de las variables de entorno correspondientes.
    | Comentario: pendiente de confirmar con el PO.
    |
    */

    // Duración máxima permitida para un préstamo en días.
    'duracion_maxima_dias' => (int) env('PRESTAMO_DURACION_MAXIMA_DIAS', 7),

    // Número máximo de préstamos activos (solicitado, aprobado, entregado) por usuario.
    'max_activos_por_usuario' => (int) env('PRESTAMO_MAX_ACTIVOS_POR_USUARIO', 3),
];

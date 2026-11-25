<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Esta configuración permite CORS en rutas de API para desarrollo local.
    | Si el frontend es Inertia+Vue, las requests del navegador necesitan
    | este CORS habilitado. Si el frontend termina siendo Blade/Livewire
    | puro (server-to-server), esta config simplemente no se usa activamente
    | pero no hace falta quitarla.
    |
    | IMPORTANTE: allowed_origins debe ajustarse por ambiente en producción.
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];

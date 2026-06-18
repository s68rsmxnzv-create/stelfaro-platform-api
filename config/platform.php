<?php

return [
    'hosts' => [
        'platform' => env('PLATFORM_HOST', 'platform.stelfaro.com'),
        'taller' => env('TALLER_HOST', 'taller.stelfaro.com'),
        'facturacion' => env('FACTURACION_HOST', 'facturacion.stelfaro.com'),
        'admin' => env('ADMIN_HOST', 'admin.stelfaro.com'),
    ],

    'admin' => [
        'emails' => array_values(array_filter(array_map(
            fn (string $email): string => strtolower(trim($email)),
            explode(',', (string) env('PLATFORM_ADMIN_EMAILS', ''))
        ))),
        'membership_roles' => ['platform_owner', 'platform_admin'],
    ],
];

<?php

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        'https://'.env('ADMIN_HOST', 'admin.stelfaro.com'),
        'https://'.env('PLATFORM_HOST', 'platform.stelfaro.com'),
    ],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];

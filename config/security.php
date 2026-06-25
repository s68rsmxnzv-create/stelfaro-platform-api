<?php

return [
    'headers' => [
        'enabled' => env('SECURITY_HEADERS_ENABLED', true),
        'csp_report_only' => env('SECURITY_CSP_REPORT_ONLY', false),
    ],

    'suspicious_input' => [
        'enabled' => env('SECURITY_BLOCK_SUSPICIOUS_INPUT', true),
        'message' => 'Intento bloqueado. En Stelfaro protegemos a nuestros clientes; por aquí no se juega.',
        'except' => [
            'api/v1/webhooks/wompi',
        ],
    ],
];

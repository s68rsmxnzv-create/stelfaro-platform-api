<?php

use App\Support\Platform\PlatformRoles;

return [
    'portal' => [
        'host' => env('APP_PORTAL_HOST', 'new.stelfaro.com'),
        'scheme' => env('APP_PORTAL_SCHEME', 'https'),
    ],

    'paths' => [
        'taller' => '/taller',
        'facturacion' => '/facturacion',
        'admin' => '/administracion',
    ],

    'hosts' => [
        'platform' => env('PLATFORM_HOST', 'new.stelfaro.com'),
        'taller' => env('TALLER_HOST', 'new.stelfaro.com'),
        'facturacion' => env('FACTURACION_HOST', 'new.stelfaro.com'),
        'admin' => env('ADMIN_HOST', 'new.stelfaro.com'),
    ],

    'admin' => [
        'emails' => array_values(array_filter(array_map(
            fn (string $email): string => strtolower(trim($email)),
            explode(',', (string) env('PLATFORM_ADMIN_EMAILS', ''))
        ))),
        'platform_emails' => array_values(array_filter(array_map(
            fn (string $email): string => strtolower(trim($email)),
            explode(',', (string) env('PLATFORM_ADMIN_EMAILS', ''))
        ))),
        'fiscal_emails' => array_values(array_filter(array_map(
            fn (string $email): string => strtolower(trim($email)),
            explode(',', (string) env('PLATFORM_FISCAL_ADMIN_EMAILS', ''))
        ))),
        'platform_roles' => PlatformRoles::globalAdminRoles(),
        'fiscal_platform_roles' => [PlatformRoles::PLATFORM_OWNER],
        'membership_roles' => [],
        'platform_membership_roles' => [],
        'fiscal_membership_roles' => ['fiscal_admin'],
    ],

    'roles' => [
        'global' => PlatformRoles::globalRoles(),
        'global_admin' => PlatformRoles::globalAdminRoles(),
        'tenant' => PlatformRoles::tenantRoles(),
        'tenant_user_admin' => PlatformRoles::tenantUserAdminRoles(),
        'fiscal_session' => PlatformRoles::fiscalSessionRoles(),
    ],
];

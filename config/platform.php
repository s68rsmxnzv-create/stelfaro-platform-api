<?php

use App\Support\Platform\PlatformRoles;

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

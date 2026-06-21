<?php

namespace App\Support\Platform;

final class PlatformRoles
{
    public const PLATFORM_OWNER = 'platform_owner';

    public const PLATFORM_ADMIN = 'platform_admin';

    public const SUPPORT_AGENT = 'support_agent';

    public const OWNER = 'owner';

    public const COMPANY_ADMIN = 'company_admin';

    public const BILLING_ADMIN = 'billing_admin';

    public const BILLING_USER = 'billing_user';

    public const VIEWER = 'viewer';

    public const FISCAL_ADMIN = 'admin_fiscal';

    public const FISCAL_COMPANY_ADMIN = 'company_admin';

    public const FISCAL_BILLING_USER = 'billing_user';

    public const FISCAL_VIEWER = 'viewer';

    /**
     * @return list<string>
     */
    public static function globalRoles(): array
    {
        return [
            self::PLATFORM_OWNER,
            self::PLATFORM_ADMIN,
            self::SUPPORT_AGENT,
        ];
    }

    /**
     * @return list<string>
     */
    public static function globalAdminRoles(): array
    {
        return [
            self::PLATFORM_OWNER,
            self::PLATFORM_ADMIN,
        ];
    }

    /**
     * @return list<string>
     */
    public static function tenantRoles(): array
    {
        return [
            self::OWNER,
            self::COMPANY_ADMIN,
            self::BILLING_ADMIN,
            self::BILLING_USER,
            self::VIEWER,
        ];
    }

    /**
     * @return list<string>
     */
    public static function tenantUserAdminRoles(): array
    {
        return [
            self::OWNER,
            self::COMPANY_ADMIN,
        ];
    }

    /**
     * @return list<string>
     */
    public static function fiscalSessionRoles(): array
    {
        return self::tenantRoles();
    }

    public static function isGlobalRole(string $role): bool
    {
        return in_array($role, self::globalRoles(), true);
    }

    public static function isGlobalAdminRole(string $role): bool
    {
        return in_array($role, self::globalAdminRoles(), true);
    }

    public static function isTenantRole(string $role): bool
    {
        return in_array($role, self::tenantRoles(), true);
    }

    public static function isTenantUserAdminRole(string $role): bool
    {
        return in_array($role, self::tenantUserAdminRoles(), true);
    }

    public static function fiscalRoleForTenantRole(string $role): string
    {
        return match ($role) {
            self::OWNER, self::COMPANY_ADMIN, self::BILLING_ADMIN => self::FISCAL_COMPANY_ADMIN,
            self::VIEWER => self::FISCAL_VIEWER,
            default => self::FISCAL_BILLING_USER,
        };
    }
}

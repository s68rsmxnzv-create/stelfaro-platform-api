<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use App\Models\UserTenantMembership;
use App\Support\Platform\PlatformRoles;

class PlatformAccessPolicy
{
    public function canViewGlobalUsers(?User $user): bool
    {
        return $this->hasPlatformOwnerRole($user);
    }

    public function canCreateGlobalUsers(?User $user): bool
    {
        return $this->hasPlatformOwnerRole($user);
    }

    public function canViewTenantUsers(?User $user, Tenant|int $tenant): bool
    {
        return $this->hasGlobalAdminRole($user)
            || $this->activeMembershipFor($user, $tenant) !== null;
    }

    public function canInviteTenantUsers(?User $user, Tenant|int $tenant): bool
    {
        return $this->hasGlobalAdminRole($user)
            || $this->hasTenantUserAdminRole($user, $tenant);
    }

    public function canChangeTenantMemberRole(?User $user, Tenant|int $tenant): bool
    {
        return $this->hasGlobalAdminRole($user)
            || $this->hasTenantUserAdminRole($user, $tenant);
    }

    public function canSuspendTenantMember(?User $user, Tenant|int $tenant): bool
    {
        return $this->hasGlobalAdminRole($user)
            || $this->hasTenantUserAdminRole($user, $tenant);
    }

    public function canReactivateTenantMember(?User $user, Tenant|int $tenant): bool
    {
        return $this->canSuspendTenantMember($user, $tenant);
    }

    public function canRemoveTenantAccess(?User $user, Tenant|int $tenant): bool
    {
        return $this->hasGlobalAdminRole($user)
            || $this->hasTenantUserAdminRole($user, $tenant);
    }

    public function canChangeActiveTenant(?User $user, UserTenantMembership $membership): bool
    {
        return $user !== null
            && $membership->user_id === $user->id
            && $membership->status === 'active'
            && $membership->tenant !== null
            && $membership->tenant->status === 'active';
    }

    public function canAccessFiscalSession(?User $user, ?UserTenantMembership $membership): bool
    {
        return $user !== null
            && $membership !== null
            && $membership->user_id === $user->id
            && $membership->status === 'active'
            && $membership->tenant !== null
            && $membership->tenant->status === 'active'
            && in_array($membership->role, PlatformRoles::fiscalSessionRoles(), true);
    }

    public function fiscalRoleFor(UserTenantMembership $membership): string
    {
        return PlatformRoles::fiscalRoleForTenantRole($membership->role);
    }

    public function hasPlatformOwnerRole(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($this->hasPlatformOwnerBootstrapEmail($user)) {
            return true;
        }

        return $user->memberships()
            ->where('status', 'active')
            ->where('role', PlatformRoles::PLATFORM_OWNER)
            ->exists();
    }

    private function hasPlatformOwnerBootstrapEmail(User $user): bool
    {
        $email = strtolower(trim($user->email));
        $ownerEmails = array_values(array_unique([
            ...config('platform.admin.platform_emails', []),
            ...config('platform.admin.emails', []),
        ]));

        return in_array($email, $ownerEmails, true);
    }

    private function hasGlobalAdminRole(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $user->memberships()
            ->where('status', 'active')
            ->whereIn('role', PlatformRoles::globalAdminRoles())
            ->exists();
    }

    private function hasTenantUserAdminRole(?User $user, Tenant|int $tenant): bool
    {
        $membership = $this->activeMembershipFor($user, $tenant);

        return $membership !== null
            && PlatformRoles::isTenantUserAdminRole($membership->role);
    }

    private function activeMembershipFor(?User $user, Tenant|int $tenant): ?UserTenantMembership
    {
        if (! $user) {
            return null;
        }

        $tenantId = $tenant instanceof Tenant ? $tenant->id : $tenant;

        return $user->memberships()
            ->with('tenant')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->first();
    }
}

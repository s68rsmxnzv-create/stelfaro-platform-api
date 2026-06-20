<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use App\Models\UserTenantMembership;
use Illuminate\Support\Facades\DB;

class UserTenantMembershipManager
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function create(User $user, Tenant $tenant, string $role, array $metadata = [], bool $makeDefault = false): UserTenantMembership
    {
        return DB::transaction(function () use ($user, $tenant, $role, $metadata, $makeDefault): UserTenantMembership {
            $hasDefault = $user->memberships()
                ->where('is_default', true)
                ->lockForUpdate()
                ->exists();

            if ($makeDefault) {
                $user->memberships()
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            return $user->memberships()->create([
                'tenant_id' => $tenant->id,
                'role' => $role,
                'status' => 'active',
                'is_default' => $makeDefault || ! $hasDefault,
                'metadata' => $metadata,
            ]);
        });
    }

    public function setDefault(User $user, UserTenantMembership $membership): UserTenantMembership
    {
        return DB::transaction(function () use ($user, $membership): UserTenantMembership {
            $membership = $user->memberships()
                ->whereKey($membership->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->firstOrFail();

            $user->memberships()
                ->where('is_default', true)
                ->update(['is_default' => false]);

            $membership->forceFill(['is_default' => true])->save();

            return $membership->refresh();
        });
    }
}

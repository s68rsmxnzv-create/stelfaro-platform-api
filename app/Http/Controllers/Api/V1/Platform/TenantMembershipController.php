<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\UserTenantMembership;
use App\Services\PlatformAccessPolicy;
use App\Support\Platform\PlatformRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TenantMembershipController extends Controller
{
    public function updateRole(Request $request, UserTenantMembership $membership, PlatformAccessPolicy $policy): JsonResponse
    {
        $membership->load('tenant', 'user');
        abort_unless($policy->canChangeTenantMemberRole($request->user(), $membership->tenant), 403);
        $this->abortIfProtectedOwner($request, $membership, $policy);

        $validated = $request->validate([
            'role' => ['required', 'string', Rule::in([
                PlatformRoles::COMPANY_ADMIN,
                PlatformRoles::BILLING_ADMIN,
                PlatformRoles::BILLING_USER,
                PlatformRoles::VIEWER,
            ])],
        ]);

        $membership->forceFill(['role' => $validated['role']])->save();

        return response()->json(['membership' => $this->payload($membership->refresh())]);
    }

    public function suspend(Request $request, UserTenantMembership $membership, PlatformAccessPolicy $policy): JsonResponse
    {
        $membership->load('tenant', 'user');
        abort_unless($policy->canSuspendTenantMember($request->user(), $membership->tenant), 403);
        $this->abortIfProtectedOwner($request, $membership, $policy);

        $membership->forceFill(['status' => 'suspended', 'is_default' => false])->save();

        return response()->json(['membership' => $this->payload($membership->refresh())]);
    }

    public function reactivate(Request $request, UserTenantMembership $membership, PlatformAccessPolicy $policy): JsonResponse
    {
        $membership->load('tenant', 'user');
        abort_unless($policy->canReactivateTenantMember($request->user(), $membership->tenant), 403);

        $membership->forceFill(['status' => 'active'])->save();

        return response()->json(['membership' => $this->payload($membership->refresh())]);
    }

    public function destroy(Request $request, UserTenantMembership $membership, PlatformAccessPolicy $policy): JsonResponse
    {
        $membership->load('tenant', 'user');
        abort_unless($policy->canRemoveTenantAccess($request->user(), $membership->tenant), 403);
        $this->abortIfProtectedOwner($request, $membership, $policy);

        $membership->forceFill(['status' => 'removed', 'is_default' => false])->save();

        return response()->json(['membership' => $this->payload($membership->refresh())]);
    }

    public function setActive(Request $request, UserTenantMembership $membership, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canChangeActiveTenant($request->user(), $membership->load('tenant')), 403);

        $request->user()->memberships()
            ->where('is_default', true)
            ->update(['is_default' => false]);

        $membership->newQuery()
            ->whereKey($membership->id)
            ->update(['is_default' => true]);

        return response()->json(['membership' => $this->payload($membership->refresh())]);
    }

    private function abortIfProtectedOwner(Request $request, UserTenantMembership $membership, PlatformAccessPolicy $policy): void
    {
        if ($membership->role === PlatformRoles::OWNER && ! $policy->hasPlatformOwnerRole($request->user())) {
            abort(403, 'Solo platform_owner puede modificar al owner de la empresa.');
        }
    }

    private function payload(UserTenantMembership $membership): array
    {
        return [
            'id' => $membership->id,
            'tenant_id' => $membership->tenant_id,
            'user_id' => $membership->user_id,
            'role' => $membership->role,
            'status' => $membership->status,
            'is_default' => (bool) $membership->is_default,
        ];
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\UserTenantMembership;
use App\Services\CoreBillingSessionBroker;
use App\Services\PlatformAccessPolicy;
use App\Services\Platform\TemporaryPasswordNotificationClient;
use App\Services\Platform\TenantEnvironmentResolver;
use App\Support\Platform\PlatformRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

class TenantMembershipController extends Controller
{
    public function updateRole(Request $request, UserTenantMembership $membership, PlatformAccessPolicy $policy, CoreBillingSessionBroker $billingSessions): JsonResponse
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
                PlatformRoles::ACCOUNTANT,
                PlatformRoles::SELLER,
            ])],
        ]);

        if ($membership->role !== $validated['role']) {
            $this->revokeFiscalSessions($membership, $billingSessions);
            $membership->forceFill(['role' => $validated['role']])->save();
        }

        return response()->json(['membership' => $this->payload($membership->refresh())]);
    }

    public function suspend(Request $request, UserTenantMembership $membership, PlatformAccessPolicy $policy, CoreBillingSessionBroker $billingSessions): JsonResponse
    {
        $membership->load('tenant', 'user');
        abort_unless($policy->canSuspendTenantMember($request->user(), $membership->tenant), 403);
        $this->abortIfProtectedOwner($request, $membership, $policy);

        $this->revokeFiscalSessions($membership, $billingSessions);
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

    public function destroy(Request $request, UserTenantMembership $membership, PlatformAccessPolicy $policy, CoreBillingSessionBroker $billingSessions): JsonResponse
    {
        $membership->load('tenant', 'user');
        abort_unless($policy->canRemoveTenantAccess($request->user(), $membership->tenant), 403);
        $this->abortIfProtectedOwner($request, $membership, $policy);

        $this->revokeFiscalSessions($membership, $billingSessions);
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

    public function resetTemporaryPassword(Request $request, UserTenantMembership $membership, PlatformAccessPolicy $policy, CoreBillingSessionBroker $billingSessions, TenantEnvironmentResolver $environmentResolver, TemporaryPasswordNotificationClient $temporaryPasswords): JsonResponse
    {
        $membership->load('tenant', 'user');
        abort_unless($policy->canSuspendTenantMember($request->user(), $membership->tenant), 403);
        $this->abortIfProtectedOwner($request, $membership, $policy);
        abort_unless($membership->user !== null, 404, 'La membresia no tiene usuario vinculado.');

        $this->revokeFiscalSessions($membership, $billingSessions);
        $temporaryPassword = $this->temporaryPassword();
        $membership->user->forceFill([
            'password' => $temporaryPassword,
            'must_change_password' => true,
            'password_changed_at' => null,
            'temporary_password_expires_at' => now()->addHours((int) config('auth.temporary_password_ttl_hours', 72)),
        ])->save();

        $delivery = null;
        if ($environmentResolver->isProduction($membership->tenant)) {
            try {
                $delivery = $temporaryPasswords->send(
                    $membership->tenant,
                    $membership->user,
                    $membership->role,
                    $temporaryPassword,
                    reason: 'password_reset',
                    purpose: 'platform_account_activation',
                    subject: 'Nueva contraseña de acceso a '.$membership->tenant->name,
                );
            } catch (RuntimeException $exception) {
                report($exception);
                $delivery = ['status' => 'failed', 'error' => $exception->getMessage()];
            }
        }

        return response()->json([
            'membership' => $this->payload($membership->refresh()),
            'user' => [
                'id' => $membership->user->id,
                'name' => $membership->user->name,
                'email' => $membership->user->email,
                'must_change_password' => true,
            ],
            'temporary_password' => $temporaryPassword,
            'temporary_password_delivery' => $delivery,
        ]);
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

    private function temporaryPassword(): string
    {
        return 'Sf-'.Str::password(12, letters: true, numbers: true, symbols: false);
    }

    private function revokeFiscalSessions(UserTenantMembership $membership, CoreBillingSessionBroker $billingSessions): void
    {
        try {
            $billingSessions->revokePlatformUsers([$membership->user_id]);
        } catch (RuntimeException) {
            abort(503, 'No pudimos cerrar el acceso fiscal activo. No se aplico el cambio; intenta nuevamente.');
        }
    }
}

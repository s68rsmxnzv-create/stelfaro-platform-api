<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\UserInvitation;
use App\Services\Platform\DirectTenantUserService;
use App\Services\Platform\TemporaryPasswordNotificationClient;
use App\Services\Platform\TenantEnvironmentResolver;
use App\Services\Platform\UserInvitationService;
use App\Services\PlatformAccessPolicy;
use App\Support\Platform\PlatformRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TenantUserController extends Controller
{
    public function index(Request $request, Tenant $tenant, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canViewTenantUsers($request->user(), $tenant), 403);

        $memberships = $tenant->memberships()
            ->with('user', 'fiscalAssignments')
            ->orderBy('role')
            ->get()
            ->map(fn ($membership): array => [
                'id' => $membership->id,
                'user' => [
                    'id' => $membership->user?->id,
                    'name' => $membership->user?->name,
                    'email' => $membership->user?->email,
                    'must_change_password' => (bool) $membership->user?->must_change_password,
                    'password_changed_at' => $membership->user?->password_changed_at?->toISOString(),
                ],
                'role' => $membership->role,
                'status' => $membership->status,
                'is_default' => (bool) $membership->is_default,
                'fiscal_assignments' => $membership->fiscalAssignments->map(fn ($assignment): array => [
                    'id' => $assignment->id,
                    'core_empresa_id' => $assignment->core_empresa_id,
                    'core_sucursal_id' => $assignment->core_sucursal_id,
                    'core_punto_venta_id' => $assignment->core_punto_venta_id,
                    'is_default' => (bool) $assignment->is_default,
                    'status' => $assignment->status,
                ])->values(),
            ])
            ->values();

        $invitations = $tenant->invitations()
            ->with('inviter')
            ->latest()
            ->get()
            ->map(fn (UserInvitation $invitation): array => $this->invitationPayload($invitation))
            ->values();

        return response()->json([
            'tenant' => [
                'id' => $tenant->id,
                'slug' => $tenant->slug,
                'name' => $tenant->name,
                'status' => $tenant->status,
            ],
            'memberships' => $memberships,
            'invitations' => $invitations,
        ]);
    }

    public function invite(
        Request $request,
        Tenant $tenant,
        PlatformAccessPolicy $policy,
        UserInvitationService $invitations,
    ): JsonResponse {
        abort_unless($policy->canInviteTenantUsers($request->user(), $tenant), 403);

        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'role' => ['required', 'string', Rule::in([
                PlatformRoles::COMPANY_ADMIN,
                PlatformRoles::BILLING_ADMIN,
                PlatformRoles::BILLING_USER,
                PlatformRoles::VIEWER,
            ])],
        ]);

        $result = $invitations->invite(
            $tenant,
            (string) $validated['email'],
            (string) $validated['role'],
            $request->user(),
        );

        return response()->json([
            'invitation' => $this->invitationPayload($result['invitation']),
            'token' => $result['token'],
        ], 201);
    }

    public function store(
        Request $request,
        Tenant $tenant,
        PlatformAccessPolicy $policy,
        DirectTenantUserService $users,
        TenantEnvironmentResolver $environmentResolver,
        TemporaryPasswordNotificationClient $temporaryPasswords,
    ): JsonResponse {
        abort_unless($policy->canInviteTenantUsers($request->user(), $tenant), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'role' => ['required', 'string', Rule::in([
                PlatformRoles::OWNER,
                PlatformRoles::COMPANY_ADMIN,
                PlatformRoles::BILLING_ADMIN,
                PlatformRoles::BILLING_USER,
                PlatformRoles::VIEWER,
            ])],
        ]);

        $result = $users->create(
            $tenant,
            (string) $validated['name'],
            (string) $validated['email'],
            (string) $validated['role'],
            $request->user(),
        );
        $delivery = null;
        $visibleTemporaryPassword = $result['temporary_password'];

        if ($environmentResolver->isProduction($tenant) && $result['created'] && $result['temporary_password']) {
            $delivery = $temporaryPasswords->send(
                $tenant,
                $result['user'],
                (string) $validated['role'],
                (string) $result['temporary_password'],
                'direct_user_creation',
            );
            $visibleTemporaryPassword = null;
        }

        return response()->json([
            'user' => [
                'id' => $result['user']->id,
                'name' => $result['user']->name,
                'email' => $result['user']->email,
                'must_change_password' => (bool) $result['user']->must_change_password,
            ],
            'temporary_password' => $visibleTemporaryPassword,
            'temporary_password_delivery' => $delivery,
            'created' => $result['created'],
        ], 201);
    }

    private function invitationPayload(UserInvitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'tenant_id' => $invitation->tenant_id,
            'email' => $invitation->email,
            'role' => $invitation->role,
            'status' => $invitation->status,
            'expires_at' => $invitation->expires_at?->toISOString(),
            'accepted_at' => $invitation->accepted_at?->toISOString(),
            'invited_by' => $invitation->inviter ? [
                'id' => $invitation->inviter->id,
                'name' => $invitation->inviter->name,
                'email' => $invitation->inviter->email,
            ] : null,
        ];
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\UserInvitation;
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
            ->with('user')
            ->orderBy('role')
            ->get()
            ->map(fn ($membership): array => [
                'id' => $membership->id,
                'user' => [
                    'id' => $membership->user?->id,
                    'name' => $membership->user?->name,
                    'email' => $membership->user?->email,
                ],
                'role' => $membership->role,
                'status' => $membership->status,
                'is_default' => (bool) $membership->is_default,
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

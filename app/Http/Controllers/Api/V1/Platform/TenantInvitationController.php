<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\UserInvitation;
use App\Services\Platform\UserInvitationService;
use App\Services\PlatformAccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantInvitationController extends Controller
{
    public function accept(Request $request, string $token, UserInvitationService $invitations): JsonResponse
    {
        $invitation = $invitations->accept($token, $request->user());

        return response()->json([
            'invitation' => [
                'id' => $invitation->id,
                'tenant_id' => $invitation->tenant_id,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'status' => $invitation->status,
                'accepted_at' => $invitation->accepted_at?->toISOString(),
            ],
        ]);
    }

    public function resend(
        Request $request,
        UserInvitation $invitation,
        PlatformAccessPolicy $policy,
        UserInvitationService $invitations,
    ): JsonResponse {
        abort_unless($policy->canInviteTenantUsers($request->user(), $invitation->tenant_id), 403);

        $result = $invitations->resend($invitation);

        return response()->json([
            'invitation' => [
                'id' => $result['invitation']->id,
                'tenant_id' => $result['invitation']->tenant_id,
                'email' => $result['invitation']->email,
                'role' => $result['invitation']->role,
                'status' => $result['invitation']->status,
                'expires_at' => $result['invitation']->expires_at?->toISOString(),
            ],
            'token' => $result['token'],
        ]);
    }
}

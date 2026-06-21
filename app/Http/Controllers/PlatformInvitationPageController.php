<?php

namespace App\Http\Controllers;

use App\Models\UserInvitation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PlatformInvitationPageController extends Controller
{
    public function __invoke(Request $request, string $token): Response
    {
        $invitation = UserInvitation::query()
            ->with('tenant')
            ->where('token_hash', hash('sha256', $token))
            ->first();

        return Inertia::render('Invitations/Accept', [
            'token' => $token,
            'invitation' => $invitation ? [
                'email' => $invitation->email,
                'role' => $invitation->role,
                'status' => $invitation->status,
                'expires_at' => $invitation->expires_at?->toISOString(),
                'tenant' => [
                    'name' => $invitation->tenant?->name,
                    'slug' => $invitation->tenant?->slug,
                ],
            ] : null,
            'user' => $request->user() ? [
                'email' => $request->user()->email,
                'name' => $request->user()->name,
            ] : null,
        ]);
    }
}

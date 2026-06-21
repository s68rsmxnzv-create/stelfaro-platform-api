<?php

namespace App\Services\Platform;

use App\Models\UserInvitation;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class InvitationNotificationClient
{
    public function send(UserInvitation $invitation, string $acceptUrl): void
    {
        $baseUrl = rtrim((string) config('services.notifications.base_url'), '/');
        $token = (string) config('services.notifications.internal_token', '');

        if ($baseUrl === '' || $token === '') {
            return;
        }

        $response = Http::acceptJson()
            ->withToken($token)
            ->timeout(10)
            ->post($baseUrl.'/platform/invitations/email', [
                'recipient' => [
                    'email' => $invitation->email,
                ],
                'tenant' => [
                    'id' => $invitation->tenant_id,
                    'name' => $invitation->tenant?->name,
                    'slug' => $invitation->tenant?->slug,
                ],
                'invitation' => [
                    'id' => $invitation->id,
                    'role' => $invitation->role,
                    'status' => $invitation->status,
                    'expires_at' => $invitation->expires_at?->toISOString(),
                    'accept_url' => $acceptUrl,
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('No fue posible enviar la invitacion.');
        }
    }
}

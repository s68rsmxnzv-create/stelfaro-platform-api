<?php

namespace App\Services\Platform;

use App\Models\UserInvitation;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class InvitationNotificationClient
{
    /**
     * @return array<string, mixed>|null
     */
    public function send(UserInvitation $invitation, string $acceptUrl): ?array
    {
        $baseUrl = rtrim((string) config('services.notifications.base_url'), '/');
        $token = (string) config('services.notifications.internal_token', '');

        if ($baseUrl === '' || $token === '') {
            return null;
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

        $data = $response->json('data');

        return is_array($data) ? $data : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function status(UserInvitation $invitation): ?array
    {
        $messageId = data_get($invitation->metadata, 'notification.message_id');
        $baseUrl = rtrim((string) config('services.notifications.base_url'), '/');
        $token = (string) config('services.notifications.internal_token', '');

        if (! $messageId || $baseUrl === '' || $token === '') {
            return null;
        }

        $response = Http::acceptJson()
            ->withToken($token)
            ->timeout(10)
            ->get($baseUrl.'/messages/'.$messageId);

        if ($response->failed()) {
            return null;
        }

        $data = $response->json('data');

        return is_array($data) ? $data : null;
    }
}

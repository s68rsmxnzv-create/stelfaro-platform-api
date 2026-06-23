<?php

namespace App\Services\Platform;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TemporaryPasswordNotificationClient
{
    /**
     * @return array<string, mixed>|null
     */
    public function send(Tenant $tenant, User $user, string $role, string $temporaryPassword, string $reason): ?array
    {
        $baseUrl = rtrim((string) config('services.notifications.base_url'), '/');
        $token = (string) config('services.notifications.internal_token', '');

        if ($baseUrl === '' || $token === '') {
            return null;
        }

        $response = Http::acceptJson()
            ->withToken($token)
            ->timeout(10)
            ->post($baseUrl.'/platform/temporary-passwords/email', [
                'recipient' => [
                    'email' => $user->email,
                    'name' => $user->name,
                ],
                'tenant' => [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                ],
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $role,
                ],
                'temporary_password' => [
                    'value' => $temporaryPassword,
                    'login_url' => rtrim((string) config('app.url'), '/').'/login',
                    'must_change' => true,
                    'reason' => $reason,
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('No fue posible enviar la contrasena temporal.');
        }

        $data = $response->json('data');

        return is_array($data) ? $data : null;
    }
}

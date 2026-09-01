<?php

namespace App\Services\Platform;

use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use RuntimeException;

class PasswordResetNotificationClient
{
    /**
     * Envia el correo de restablecimiento a traves del servicio de notificaciones.
     *
     * @return array<string, mixed>|null  null cuando el servicio no esta configurado
     */
    public function send(CanResetPassword $notifiable, string $token): ?array
    {
        $baseUrl = rtrim((string) config('services.notifications.base_url'), '/');
        $internalToken = (string) config('services.notifications.internal_token', '');

        if ($baseUrl === '' || $internalToken === '') {
            return null;
        }

        $response = Http::acceptJson()
            ->withToken($internalToken)
            ->timeout(10)
            ->post($baseUrl.'/platform/password-reset/email', [
                'recipient' => [
                    'email' => $notifiable->getEmailForPasswordReset(),
                    'name' => $notifiable->name ?? null,
                ],
                'reset' => [
                    'url' => $this->resetUrl($notifiable, $token),
                    'expires_minutes' => (int) config(
                        'auth.passwords.'.config('auth.defaults.passwords').'.expire',
                        60
                    ),
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('No fue posible enviar el correo de restablecimiento de contrasena.');
        }

        $data = $response->json('data');

        return is_array($data) ? $data : null;
    }

    private function resetUrl(CanResetPassword $notifiable, string $token): string
    {
        return url(Route::has('password.reset')
            ? route('password.reset', [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ], false)
            : 'reset-password/'.$token.'?email='.urlencode($notifiable->getEmailForPasswordReset()));
    }
}

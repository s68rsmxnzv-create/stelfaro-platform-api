<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CoreBillingSessionBroker
{
    /**
     * @return array<string, mixed>
     */
    public function openFor(User $user): array
    {
        return $this->open($user->email, 'stelfaro-platform');
    }

    /**
     * @return array<string, mixed>
     */
    public function openBackoffice(): array
    {
        $email = config('services.dte_core.admin_email');
        $deviceName = config('services.dte_core.admin_device_name', 'stelfaro-platform-admin');

        if (! is_string($email) || $email === '') {
            throw new RuntimeException('La cuenta fiscal backoffice no esta configurada.');
        }

        return $this->open($email, is_string($deviceName) && $deviceName !== '' ? $deviceName : 'stelfaro-platform-admin');
    }

    /**
     * @return array<string, mixed>
     */
    private function open(string $email, string $deviceName): array
    {
        $baseUrl = rtrim((string) config('services.dte_core.base_url'), '/');
        $bridgePassword = config('services.dte_core.bridge_password');

        if ($baseUrl === '' || ! is_string($bridgePassword) || $bridgePassword === '') {
            throw new RuntimeException('La conexion con el core fiscal no esta configurada.');
        }

        $response = Http::acceptJson()
            ->timeout(15)
            ->post($baseUrl.'/auth/login', [
                'email' => $email,
                'password' => $bridgePassword,
                'device_name' => $deviceName,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('No fue posible abrir la sesion fiscal.');
        }

        return $response->json();
    }
}

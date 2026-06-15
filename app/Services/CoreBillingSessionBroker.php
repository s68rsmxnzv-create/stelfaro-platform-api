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
        $baseUrl = rtrim((string) config('services.dte_core.base_url'), '/');
        $bridgePassword = config('services.dte_core.bridge_password');

        if ($baseUrl === '' || ! is_string($bridgePassword) || $bridgePassword === '') {
            throw new RuntimeException('La conexion con el core fiscal no esta configurada.');
        }

        $response = Http::acceptJson()
            ->timeout(15)
            ->post($baseUrl.'/auth/login', [
                'email' => $user->email,
                'password' => $bridgePassword,
                'device_name' => 'stelfaro-platform',
            ]);

        if ($response->failed()) {
            throw new RuntimeException('No fue posible abrir la sesion fiscal.');
        }

        return $response->json();
    }
}

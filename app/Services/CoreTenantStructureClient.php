<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class CoreTenantStructureClient
{
    public function __construct(private readonly CoreBillingSessionBroker $broker) {}

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function createBranch(int $empresaId, array $payload): array
    {
        return $this->post("/billing/companies/{$empresaId}/sucursales", $payload);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function createPointOfSale(int $sucursalId, array $payload): array
    {
        return $this->post("/billing/sucursales/{$sucursalId}/puntos-venta", $payload);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function post(string $path, array $payload): array
    {
        $baseUrl = rtrim((string) config('services.dte_core.base_url'), '/');
        $session = $this->broker->openBackoffice();
        $token = (string) ($session['token'] ?? '');
        if ($baseUrl === '' || $token === '') {
            throw new RuntimeException('No fue posible abrir la administración fiscal.');
        }

        $response = Http::acceptJson()->withToken($token)->timeout(30)->post($baseUrl.$path, $payload);
        if ($response->failed()) {
            throw new RuntimeException((string) ($response->json('message') ?? data_get($response->json(), 'errors.0.0') ?? 'No fue posible crear la estructura fiscal.'));
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new RuntimeException('El core fiscal no devolvió una respuesta válida.');
        }

        return $data;
    }
}

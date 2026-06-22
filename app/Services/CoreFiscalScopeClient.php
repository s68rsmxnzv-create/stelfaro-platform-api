<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class CoreFiscalScopeClient
{
    /**
     * @return array<string, mixed>
     */
    public function companyScope(int $coreEmpresaId): array
    {
        $baseUrl = rtrim((string) config('services.dte_core.base_url'), '/');
        $internalToken = config('services.dte_core.internal_token');

        if ($baseUrl === '' || ! is_string($internalToken) || $internalToken === '') {
            throw new RuntimeException('La conexion con el core fiscal no esta configurada.');
        }

        $response = Http::acceptJson()
            ->withToken($internalToken)
            ->timeout(15)
            ->get($baseUrl."/internal/billing/companies/{$coreEmpresaId}/fiscal-scope");

        if ($response->failed()) {
            throw new RuntimeException((string) ($response->json('message') ?? 'No fue posible consultar sucursales fiscales.'));
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('No fue posible consultar sucursales fiscales.');
        }

        return $payload;
    }
}

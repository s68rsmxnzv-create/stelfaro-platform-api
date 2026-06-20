<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class CoreFiscalCompanyValidator
{
    public function __construct(
        private readonly CoreBillingSessionBroker $broker,
    ) {}

    public function validateActiveEmpresa(int $empresaId): void
    {
        $baseUrl = rtrim((string) config('services.dte_core.base_url'), '/');

        if ($baseUrl === '') {
            throw new RuntimeException('La conexion con el core fiscal no esta configurada.');
        }

        $session = $this->broker->openBackoffice();
        $token = (string) ($session['token'] ?? '');

        if ($token === '') {
            throw new RuntimeException('No fue posible validar la empresa fiscal.');
        }

        $response = Http::acceptJson()
            ->withToken($token)
            ->timeout(15)
            ->get($baseUrl.'/billing/context');

        if ($response->failed()) {
            throw new RuntimeException('No fue posible validar la empresa fiscal.');
        }

        $companies = $response->json('empresas');
        $exists = collect(is_array($companies) ? $companies : [])
            ->contains(fn ($empresa): bool => is_array($empresa)
                && (int) ($empresa['id'] ?? 0) === $empresaId
                && ($empresa['lifecycle_status'] ?? null) === 'active');

        if (! $exists) {
            throw new RuntimeException('La empresa fiscal vinculada no existe o no esta activa.');
        }
    }
}

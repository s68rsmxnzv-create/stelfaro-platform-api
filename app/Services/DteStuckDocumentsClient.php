<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class DteStuckDocumentsClient
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        $baseUrl = rtrim((string) config('services.dte_core.base_url'), '/');
        $internalToken = config('services.dte_core.internal_token');

        if ($baseUrl === '' || ! is_string($internalToken) || $internalToken === '') {
            throw new RuntimeException('La conexion con el core fiscal no esta configurada.');
        }

        $response = Http::acceptJson()
            ->withToken($internalToken)
            ->timeout(15)
            ->get($baseUrl.'/internal/dte/stuck');

        if ($response->failed()) {
            throw new RuntimeException((string) ($response->json('message') ?? 'No fue posible consultar documentos DTE atascados.'));
        }

        $data = $response->json('data', []);

        return is_array($data) ? $data : [];
    }
}

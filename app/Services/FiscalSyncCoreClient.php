<?php

namespace App\Services;

use App\Models\FiscalSyncOperation;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FiscalSyncCoreClient
{
    public function __construct(private readonly CoreBillingSessionBroker $sessions) {}

    /** @return array<string, mixed>|null */
    public function resolve(FiscalSyncOperation $operation): ?array
    {
        return match ($operation->kind) {
            FiscalSyncOperation::KIND_DTE_ISSUE => $this->dteByIdempotencyKey($operation->idempotency_key),
            FiscalSyncOperation::KIND_MH_INVALIDATION => $operation->core_resource_id
                ? $this->mhEvent($operation->core_resource_id)
                : null,
            default => throw new RuntimeException('Tipo de sincronización fiscal no soportado.'),
        };
    }

    /** @return array<string, mixed>|null */
    public function dte(string $documentId): ?array
    {
        $response = $this->client()->get($this->baseUrl().'/dte/drafts/'.$documentId);
        if ($response->status() === 404) {
            return null;
        }
        if ($response->failed()) {
            throw new RuntimeException((string) ($response->json('message') ?? 'No fue posible consultar el DTE en el core fiscal.'));
        }

        $document = $response->json();

        return is_array($document) ? $document : null;
    }

    /** @return array<string, mixed>|null */
    private function dteByIdempotencyKey(string $key): ?array
    {
        $response = $this->client()->get($this->baseUrl().'/dte/drafts', [
            'idempotency_key' => $key,
            'limit' => 1,
        ]);

        if ($response->failed()) {
            throw new RuntimeException((string) ($response->json('message') ?? 'No fue posible consultar el DTE en el core fiscal.'));
        }

        $document = $response->json('data.0');

        return is_array($document) ? $document : null;
    }

    /** @return array<string, mixed>|null */
    private function mhEvent(string $eventId): ?array
    {
        $response = $this->client()->get($this->baseUrl().'/mh/events/'.$eventId);

        if ($response->status() === 404) {
            return null;
        }

        if ($response->failed()) {
            throw new RuntimeException((string) ($response->json('message') ?? 'No fue posible consultar el evento en el core fiscal.'));
        }

        $event = $response->json();

        return is_array($event) ? $event : null;
    }

    private function client(): PendingRequest
    {
        $session = $this->sessions->openBackoffice();
        $token = (string) ($session['token'] ?? '');

        if ($token === '') {
            throw new RuntimeException('No fue posible abrir la sesión fiscal de recuperación.');
        }

        return Http::acceptJson()->withToken($token)->timeout(30);
    }

    private function baseUrl(): string
    {
        $baseUrl = rtrim((string) config('services.dte_core.base_url'), '/');

        if ($baseUrl === '') {
            throw new RuntimeException('La conexión con el core fiscal no está configurada.');
        }

        return $baseUrl;
    }
}

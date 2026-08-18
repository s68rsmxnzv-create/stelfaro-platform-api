<?php

namespace App\Services;

use App\Models\InternalNotification;
use App\Models\UserTenantMembership;

class DteStuckNotificationGenerator
{
    public function __construct(
        private readonly DteStuckDocumentsClient $client,
    ) {}

    public function generate(): int
    {
        $documents = $this->client->list();
        $created = 0;

        foreach ($documents as $document) {
            $tenantId = (int) ($document['tenant_id'] ?? 0);
            $documentId = (int) ($document['id'] ?? 0);

            if ($tenantId <= 0 || $documentId <= 0) {
                continue;
            }

            $created += $this->notifyTenant($tenantId, $documentId, $document);
        }

        return $created;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function notifyTenant(int $tenantId, int $documentId, array $document): int
    {
        $created = 0;
        $numeroControl = (string) ($document['numero_control'] ?? '');
        $empresaNombre = (string) ($document['empresa_nombre'] ?? 'tu empresa');

        UserTenantMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->chunkById(200, function ($memberships) use ($tenantId, $documentId, $numeroControl, $empresaNombre, $document, &$created): void {
                foreach ($memberships as $membership) {
                    $dedupeKey = implode(':', ['dte-stuck', $tenantId, $documentId, $membership->user_id]);

                    $notification = InternalNotification::query()->firstOrCreate(
                        ['dedupe_key' => $dedupeKey],
                        [
                            'user_id' => $membership->user_id,
                            'tenant_id' => $tenantId,
                            'category' => 'dte_stuck',
                            'title' => 'Un DTE quedó atascado y necesita tu intervención',
                            'message' => $numeroControl !== ''
                                ? "El comprobante {$numeroControl} de {$empresaNombre} no pudo completarse automáticamente. Revísalo y reintenta la emisión."
                                : "Un comprobante de {$empresaNombre} no pudo completarse automáticamente. Revísalo y reintenta la emisión.",
                            'action_url' => "/facturacion?documento_atascado={$documentId}",
                            'source_type' => 'dte_document',
                            'source_id' => (string) $documentId,
                            'metadata' => [
                                'document_id' => $documentId,
                                'numero_control' => $numeroControl,
                                'error_message' => $document['error_message'] ?? null,
                                'stuck_since' => $document['stuck_since'] ?? null,
                            ],
                        ],
                    );

                    if ($notification->wasRecentlyCreated) {
                        $created++;
                    }
                }
            });

        return $created;
    }
}

<?php

namespace App\Services;

use App\Exceptions\FiscalSyncPayloadConflictException;
use App\Models\FiscalSyncOperation;
use App\Models\InventoryReservation;
use App\Models\Tenant;
use App\Models\WorkshopOrder;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class FiscalSyncService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly FiscalSyncCoreClient $core,
    ) {}

    /** @param array<string, mixed> $data */
    public function prepareDteIssue(Tenant $tenant, array $data, ?int $userId): FiscalSyncOperation
    {
        $payload = [
            'sale' => $data['sale'],
            'reservation' => $data['reservation'] ?? null,
            'workshop_order_id' => $data['workshop_order_id'] ?? null,
        ];

        return $this->prepare($tenant, FiscalSyncOperation::KIND_DTE_ISSUE, $data['idempotency_key'], $payload, $userId, function (array &$storedPayload) use ($tenant, $data, $userId): void {
            $reservationData = $data['reservation'] ?? null;
            if (! is_array($reservationData) || empty($reservationData['lines'])) {
                return;
            }

            $reservation = $this->inventory->reserve($tenant, [
                ...$reservationData,
                'idempotency_key' => $data['idempotency_key'],
            ], $userId);
            $storedPayload['reservation_id'] = $reservation->id;
        });
    }

    /** @param array<string, mixed> $data */
    public function prepareInvalidation(Tenant $tenant, array $data, ?int $userId): FiscalSyncOperation
    {
        $payload = [
            'invalidation_type' => (int) $data['invalidation_type'],
            'original_source_id' => (string) $data['original_source_id'],
            'replacement_source_id' => isset($data['replacement_source_id']) ? (string) $data['replacement_source_id'] : null,
        ];

        $operation = $this->prepare(
            $tenant,
            FiscalSyncOperation::KIND_MH_INVALIDATION,
            $data['idempotency_key'],
            $payload,
            $userId,
        );

        if ($operation->status === 'completed' && data_get($operation->result, 'outcome') === 'rejected') {
            $operation->forceFill([
                'status' => 'pending',
                'core_resource_id' => null,
                'result' => null,
                'next_attempt_at' => null,
                'completed_at' => null,
                'last_error' => null,
            ])->save();
        }

        return $operation->refresh();
    }

    public function attachCoreResource(Tenant $tenant, FiscalSyncOperation $operation, string $resourceId): FiscalSyncOperation
    {
        abort_unless($operation->tenant_id === $tenant->id, 404);

        return DB::transaction(function () use ($operation, $resourceId): FiscalSyncOperation {
            $locked = FiscalSyncOperation::query()->lockForUpdate()->findOrFail($operation->id);

            if ($locked->core_resource_id && $locked->core_resource_id !== $resourceId) {
                throw new FiscalSyncPayloadConflictException('La sincronización ya está vinculada a otro registro fiscal.');
            }

            $locked->forceFill([
                'core_resource_id' => $resourceId,
                'status' => $locked->status === 'completed' ? 'completed' : 'pending',
                'next_attempt_at' => null,
            ])->save();

            return $locked->refresh();
        });
    }

    /** @param array<string, mixed> $fact */
    public function finalize(Tenant $tenant, FiscalSyncOperation $operation, array $fact): FiscalSyncOperation
    {
        abort_unless($operation->tenant_id === $tenant->id, 404);

        return DB::transaction(function () use ($tenant, $operation, $fact): FiscalSyncOperation {
            $locked = FiscalSyncOperation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($locked->status === 'completed' && data_get($locked->result, 'outcome') !== 'rejected') {
                return $locked;
            }

            $locked->forceFill([
                'core_resource_id' => (string) ($fact['id'] ?? $locked->core_resource_id),
                'result' => $fact,
                'last_error' => null,
            ])->save();

            return match ($locked->kind) {
                FiscalSyncOperation::KIND_DTE_ISSUE => $this->finalizeDteIssue($tenant, $locked, $fact),
                FiscalSyncOperation::KIND_MH_INVALIDATION => $this->finalizeInvalidation($tenant, $locked, $fact),
                default => throw new InvalidArgumentException('Tipo de sincronización fiscal no soportado.'),
            };
        });
    }

    /** @return array{completed:int,pending:int,failed:int} */
    public function reconcile(int $limit = 50): array
    {
        $stats = ['completed' => 0, 'pending' => 0, 'failed' => 0];
        $ids = FiscalSyncOperation::query()
            ->where(function ($query): void {
                $query->whereIn('status', ['pending', 'failed'])
                    ->orWhere(fn ($stale) => $stale->where('status', 'processing')->where('last_attempt_at', '<=', now()->subMinutes(5)));
            })
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')
            ->limit(max(1, min($limit, 200)))
            ->pluck('id');

        foreach ($ids as $id) {
            $operation = $this->claim((int) $id);
            if (! $operation) {
                continue;
            }

            try {
                $fact = $this->core->resolve($operation);
                if (! $fact) {
                    if ($operation->kind === FiscalSyncOperation::KIND_DTE_ISSUE
                        && $operation->created_at?->lte(now()->subMinutes(30))) {
                        $this->abandonUnissuedDte($operation);
                        $stats['completed']++;

                        continue;
                    }

                    $this->defer($operation);
                    $stats['pending']++;

                    continue;
                }

                $result = $this->finalize($operation->tenant, $operation, $fact);
                if ($result->status === 'completed') {
                    $stats['completed']++;
                } else {
                    $this->defer($result);
                    $stats['pending']++;
                }
            } catch (Throwable $exception) {
                $this->fail($operation, $exception);
                $stats['failed']++;
            }
        }

        return $stats;
    }

    /** @param array<string, mixed> $payload */
    private function prepare(Tenant $tenant, string $kind, string $key, array $payload, ?int $userId, ?callable $beforeCreate = null): FiscalSyncOperation
    {
        $hash = hash('sha256', json_encode($this->canonicalize($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return DB::transaction(function () use ($tenant, $kind, $key, $payload, $hash, $userId, $beforeCreate): FiscalSyncOperation {
            $existing = FiscalSyncOperation::query()
                ->where('tenant_id', $tenant->id)
                ->where('kind', $kind)
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (! hash_equals($existing->payload_hash, $hash)) {
                    throw new FiscalSyncPayloadConflictException('La clave de sincronización ya fue utilizada con datos diferentes.');
                }

                return $existing;
            }

            $storedPayload = $payload;
            if ($beforeCreate) {
                $beforeCreate($storedPayload);
            }

            return FiscalSyncOperation::query()->create([
                'tenant_id' => $tenant->id,
                'kind' => $kind,
                'idempotency_key' => $key,
                'payload_hash' => $hash,
                'status' => 'pending',
                'payload' => $storedPayload,
                'created_by' => $userId,
            ]);
        });
    }

    /** @param array<string, mixed> $fact */
    private function finalizeDteIssue(Tenant $tenant, FiscalSyncOperation $operation, array $fact): FiscalSyncOperation
    {
        $status = strtolower((string) ($fact['estado'] ?? data_get($fact, 'document.estado', '')));
        if ($status === 'rejected') {
            $this->releaseReservation($tenant, $operation);

            return $this->complete($operation, [...$fact, 'outcome' => 'rejected']);
        }

        $accepted = in_array($status, ['accepted', 'received_by_mh'], true)
            && filled($fact['selloRecibido'] ?? $fact['sello_recibido'] ?? null);
        if (! $accepted) {
            $operation->forceFill(['status' => 'pending'])->save();

            return $operation->refresh();
        }

        $documentId = (string) ($fact['id'] ?? '');
        if ($documentId === '') {
            throw new InvalidArgumentException('El core fiscal no devolvió el identificador del DTE aceptado.');
        }

        $number = (string) ($fact['numeroControl'] ?? $fact['numero_control'] ?? $fact['codigoGeneracion'] ?? '');
        $reservation = $this->reservation($tenant, $operation);
        if ($reservation) {
            $this->inventory->confirmReservation($tenant, $reservation, [
                'source_type' => 'dte',
                'source_id' => $documentId,
                'source_number' => $number,
            ], $operation->created_by);
        }

        $sale = $operation->payload['sale'];
        $sale['source_id'] = ($sale['source_type'] ?? 'dte') === 'workshop_order'
            ? (string) ($operation->payload['workshop_order_id'] ?? '')
            : $documentId;
        $sale['source_number'] = $number;
        $sale['metadata'] = array_merge($sale['metadata'] ?? [], [
            'core_dte_document_id' => (int) $documentId,
            'dte_number' => $number,
            'fiscal_sync_operation_id' => $operation->id,
        ]);
        $this->inventory->recordSale($tenant, $sale, $operation->created_by);
        $this->linkWorkshopOrder($tenant, $operation, $fact, $documentId, $number);

        return $this->complete($operation, [...$fact, 'outcome' => 'accepted']);
    }

    /** @param array<string, mixed> $fact */
    private function finalizeInvalidation(Tenant $tenant, FiscalSyncOperation $operation, array $fact): FiscalSyncOperation
    {
        $status = strtolower((string) ($fact['estado'] ?? ''));
        if ($status === 'rejected') {
            return $this->complete($operation, [...$fact, 'outcome' => 'rejected']);
        }
        if ($status !== 'accepted') {
            $operation->forceFill(['status' => 'pending'])->save();

            return $operation->refresh();
        }

        $payload = $operation->payload;
        $eventData = [
            'event_id' => (string) ($fact['id'] ?? $operation->core_resource_id),
            'event_number' => $fact['codigoGeneracion'] ?? $fact['numeroControl'] ?? null,
        ];
        if ((int) $payload['invalidation_type'] === 2) {
            $this->inventory->reverseDteSale($tenant, [
                'source_type' => 'dte',
                'source_id' => (string) $payload['original_source_id'],
                ...$eventData,
                'notes' => 'Invalidación tipo 2 aceptada por MH.',
            ], $operation->created_by);
        } elseif (filled($payload['replacement_source_id'] ?? null)) {
            $this->inventory->supersedeDteSale($tenant, [
                'source_type' => 'dte',
                'original_source_id' => (string) $payload['original_source_id'],
                'replacement_source_id' => (string) $payload['replacement_source_id'],
                ...$eventData,
            ]);
        }

        return $this->complete($operation, [...$fact, 'outcome' => 'accepted']);
    }

    /** @param array<string, mixed> $fact */
    private function linkWorkshopOrder(Tenant $tenant, FiscalSyncOperation $operation, array $fact, string $documentId, string $number): void
    {
        $orderId = (int) ($operation->payload['workshop_order_id'] ?? 0);
        if ($orderId <= 0) {
            return;
        }

        $order = WorkshopOrder::query()->where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($orderId);
        if ($order->core_dte_document_id && (string) $order->core_dte_document_id !== $documentId) {
            throw new InvalidArgumentException('La orden de taller ya está vinculada a otro DTE.');
        }
        $order->forceFill([
            'core_dte_document_id' => (int) $documentId,
            'dte_number' => $number,
            'dte_generation_code' => $fact['codigoGeneracion'] ?? $fact['codigo_generacion'] ?? null,
            'dte_type' => ($fact['tipoDte'] ?? $fact['tipo_dte'] ?? '01') === '03' ? '03' : '01',
            'billing_status' => 'invoiced',
            'invoiced_at' => now(),
        ])->save();
    }

    private function releaseReservation(Tenant $tenant, FiscalSyncOperation $operation): void
    {
        $reservation = $this->reservation($tenant, $operation);
        if ($reservation && $reservation->status === InventoryReservation::STATUS_RESERVED) {
            $this->inventory->releaseReservation($tenant, $reservation, $operation->created_by);
        }
    }

    private function reservation(Tenant $tenant, FiscalSyncOperation $operation): ?InventoryReservation
    {
        $id = (int) ($operation->payload['reservation_id'] ?? 0);

        return $id > 0
            ? InventoryReservation::query()->where('tenant_id', $tenant->id)->find($id)
            : null;
    }

    private function abandonUnissuedDte(FiscalSyncOperation $operation): void
    {
        DB::transaction(function () use ($operation): void {
            $locked = FiscalSyncOperation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($locked->status === 'completed') {
                return;
            }

            $this->releaseReservation($locked->tenant, $locked);
            $this->complete($locked, [
                'outcome' => 'not_issued',
                'message' => 'No se encontró un DTE fiscal para esta operación.',
            ]);
        });
    }

    /** @param array<string, mixed> $result */
    private function complete(FiscalSyncOperation $operation, array $result): FiscalSyncOperation
    {
        $operation->forceFill([
            'status' => 'completed',
            'result' => $result,
            'completed_at' => now(),
            'next_attempt_at' => null,
            'last_error' => null,
        ])->save();

        return $operation->refresh();
    }

    private function claim(int $id): ?FiscalSyncOperation
    {
        return DB::transaction(function () use ($id): ?FiscalSyncOperation {
            $operation = FiscalSyncOperation::query()->with('tenant')->lockForUpdate()->find($id);
            if (! $operation || $operation->status === 'completed') {
                return null;
            }
            $operation->forceFill([
                'status' => 'processing',
                'attempts' => $operation->attempts + 1,
                'last_attempt_at' => now(),
                'last_error' => null,
            ])->save();

            return $operation->refresh()->load('tenant');
        });
    }

    private function defer(FiscalSyncOperation $operation): void
    {
        $operation->forceFill([
            'status' => 'pending',
            'next_attempt_at' => now()->addMinutes(min(2 ** min($operation->attempts, 6), 60)),
        ])->save();
    }

    private function fail(FiscalSyncOperation $operation, Throwable $exception): void
    {
        $operation->forceFill([
            'status' => 'failed',
            'last_error' => mb_substr($exception->getMessage(), 0, 4000),
            'next_attempt_at' => now()->addMinutes(min(2 ** min($operation->attempts, 6), 60)),
        ])->save();
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}

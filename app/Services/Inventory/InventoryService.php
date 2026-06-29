<?php

namespace App\Services\Inventory;

use App\Models\CatalogItem;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryPurchase;
use App\Models\InventoryPurchaseLine;
use App\Models\InventoryReservation;
use App\Models\InventoryReservationLine;
use App\Models\InventorySaleAllocation;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class InventoryService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function registerPurchase(Tenant $tenant, array $data, ?int $userId = null): InventoryPurchase
    {
        return DB::transaction(function () use ($tenant, $data, $userId): InventoryPurchase {
            $subtotal = 0.0;
            $taxTotal = 0.0;

            $purchase = InventoryPurchase::query()->create([
                'tenant_id' => $tenant->id,
                'inventory_supplier_id' => $data['inventory_supplier_id'] ?? null,
                'document_type' => $this->blankToNull($data['document_type'] ?? null),
                'document_number' => $this->blankToNull($data['document_number'] ?? null),
                'purchase_date' => $data['purchase_date'],
                'status' => 'registered',
                'created_by' => $userId,
            ]);

            foreach ($data['lines'] as $line) {
                $item = $this->lockInventoryItem($tenant, (int) $line['catalog_item_id']);
                $quantity = $this->positiveQuantity($line['quantity'], 'La cantidad de compra debe ser mayor que cero.');
                $unitCost = round((float) $line['unit_cost'], 4);
                if ($unitCost < 0) {
                    throw new InvalidArgumentException('El costo unitario no puede ser negativo.');
                }

                $lineSubtotal = round($quantity * $unitCost, 2);
                $lineTax = round((float) ($line['tax_amount'] ?? 0), 2);
                $lineTotal = round($lineSubtotal + $lineTax, 2);
                $subtotal += $lineSubtotal;
                $taxTotal += $lineTax;

                $purchaseLine = InventoryPurchaseLine::query()->create([
                    'tenant_id' => $tenant->id,
                    'inventory_purchase_id' => $purchase->id,
                    'catalog_item_id' => $item->id,
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'tax_amount' => $lineTax,
                    'line_total' => $lineTotal,
                ]);

                $lot = InventoryLot::query()->create([
                    'tenant_id' => $tenant->id,
                    'catalog_item_id' => $item->id,
                    'inventory_supplier_id' => $purchase->inventory_supplier_id,
                    'inventory_purchase_id' => $purchase->id,
                    'inventory_purchase_line_id' => $purchaseLine->id,
                    'lot_code' => $this->nextLotCode($tenant, $item),
                    'received_date' => $purchase->purchase_date,
                    'unit_cost' => $unitCost,
                    'initial_quantity' => $quantity,
                    'available_quantity' => $quantity,
                    'status' => 'active',
                ]);

                $item->stock_quantity = round((float) $item->stock_quantity + $quantity, 3);
                $item->cost_source = 'real';
                $item->reference_cost = $this->averageCostForLockedItem($tenant, $item);
                $item->save();

                $this->movement($tenant, $item, $lot, 'entry', 'purchase', $quantity, $unitCost, (float) $item->stock_quantity, 'purchase', (string) $purchase->id, $purchase->document_number, null, $userId);
            }

            $purchase->subtotal = round($subtotal, 2);
            $purchase->tax_amount = round($taxTotal, 2);
            $purchase->total = round($subtotal + $taxTotal, 2);
            $purchase->save();

            return $purchase->fresh(['supplier', 'lines.catalogItem', 'lines.lots']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function reserve(Tenant $tenant, array $data, ?int $userId = null): InventoryReservation
    {
        return DB::transaction(function () use ($tenant, $data, $userId): InventoryReservation {
            $key = trim((string) $data['idempotency_key']);
            $existing = InventoryReservation::query()
                ->where('tenant_id', $tenant->id)
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing->fresh(['lines.catalogItem', 'lines.allocations.lot']);
            }

            $reservation = InventoryReservation::query()->create([
                'tenant_id' => $tenant->id,
                'idempotency_key' => $key,
                'status' => InventoryReservation::STATUS_RESERVED,
                'source_type' => $this->blankToNull($data['source_type'] ?? null),
                'source_id' => $this->blankToNull($data['source_id'] ?? null),
                'source_number' => $this->blankToNull($data['source_number'] ?? null),
                'metadata' => $data['metadata'] ?? null,
                'created_by' => $userId,
            ]);

            $quantitiesByItem = [];
            foreach ($data['lines'] as $line) {
                $itemId = (int) $line['catalog_item_id'];
                $quantity = $this->positiveQuantity($line['quantity'], 'La cantidad a reservar debe ser mayor que cero.');
                $quantitiesByItem[$itemId] = round((float) ($quantitiesByItem[$itemId] ?? 0) + $quantity, 3);
            }

            foreach ($quantitiesByItem as $itemId => $quantity) {
                $item = $this->lockInventoryItem($tenant, (int) $itemId);
                if ((float) $item->stock_quantity < $quantity) {
                    throw new InvalidArgumentException("Stock insuficiente para {$item->name}.");
                }
            }

            foreach ($data['lines'] as $line) {
                $item = $this->lockInventoryItem($tenant, (int) $line['catalog_item_id']);
                $lineModel = InventoryReservationLine::query()->create([
                    'tenant_id' => $tenant->id,
                    'inventory_reservation_id' => $reservation->id,
                    'catalog_item_id' => $item->id,
                    'quantity' => $this->positiveQuantity($line['quantity'], 'La cantidad a reservar debe ser mayor que cero.'),
                    'description_snapshot' => $this->blankToNull($line['description'] ?? null),
                ]);

                $remaining = (float) $lineModel->quantity;
                $lots = InventoryLot::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('catalog_item_id', $item->id)
                    ->where('available_quantity', '>', 0)
                    ->orderByRaw('COALESCE(received_date, DATE(created_at)) asc')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($lots as $lot) {
                    if ($remaining <= 0) {
                        break;
                    }

                    $take = min($remaining, (float) $lot->available_quantity);
                    if ($take <= 0) {
                        continue;
                    }

                    $lot->available_quantity = round((float) $lot->available_quantity - $take, 3);
                    $lot->status = (float) $lot->available_quantity > 0 ? 'active' : 'depleted';
                    $lot->save();

                    InventorySaleAllocation::query()->create([
                        'tenant_id' => $tenant->id,
                        'inventory_reservation_line_id' => $lineModel->id,
                        'catalog_item_id' => $item->id,
                        'inventory_lot_id' => $lot->id,
                        'quantity' => $take,
                        'unit_cost' => $lot->unit_cost,
                    ]);

                    $remaining = round($remaining - $take, 3);
                }

                if ($remaining > 0) {
                    throw new InvalidArgumentException("Stock insuficiente para {$item->name}.");
                }

                $item->stock_quantity = round((float) $item->stock_quantity - (float) $lineModel->quantity, 3);
                $item->save();
            }

            return $reservation->load(['lines.catalogItem', 'lines.allocations.lot']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function confirmReservation(Tenant $tenant, InventoryReservation $reservation, array $data, ?int $userId = null): InventoryReservation
    {
        return DB::transaction(function () use ($tenant, $reservation, $data, $userId): InventoryReservation {
            $reservation = InventoryReservation::query()
                ->where('tenant_id', $tenant->id)
                ->lockForUpdate()
                ->findOrFail($reservation->id);

            if ($reservation->status === InventoryReservation::STATUS_CONFIRMED) {
                return $reservation->fresh(['lines.catalogItem', 'lines.allocations.lot']);
            }
            if ($reservation->status !== InventoryReservation::STATUS_RESERVED) {
                throw new InvalidArgumentException('Solo una reserva activa puede confirmarse.');
            }

            $sourceType = $this->blankToNull($data['source_type'] ?? null) ?? $reservation->source_type ?? 'dte';
            $sourceId = $this->blankToNull($data['source_id'] ?? null) ?? $reservation->source_id;
            $sourceNumber = $this->blankToNull($data['source_number'] ?? null) ?? $reservation->source_number;

            $reservation->load('lines.allocations.lot', 'lines.catalogItem');
            foreach ($reservation->lines as $line) {
                $item = $this->lockInventoryItem($tenant, (int) $line->catalog_item_id);
                foreach ($line->allocations as $allocation) {
                    $this->movement(
                        $tenant,
                        $item,
                        $allocation->lot,
                        'exit',
                        'sale',
                        (float) $allocation->quantity,
                        (float) $allocation->unit_cost,
                        (float) $item->stock_quantity,
                        $sourceType,
                        $sourceId,
                        $sourceNumber,
                        $line->description_snapshot,
                        $userId
                    );
                }
            }

            $reservation->status = InventoryReservation::STATUS_CONFIRMED;
            $reservation->source_type = $sourceType;
            $reservation->source_id = $sourceId;
            $reservation->source_number = $sourceNumber;
            $reservation->confirmed_at = now();
            $reservation->save();

            return $reservation->fresh(['lines.catalogItem', 'lines.allocations.lot']);
        });
    }

    public function releaseReservation(Tenant $tenant, InventoryReservation $reservation, ?int $userId = null): InventoryReservation
    {
        return DB::transaction(function () use ($tenant, $reservation): InventoryReservation {
            $reservation = InventoryReservation::query()
                ->where('tenant_id', $tenant->id)
                ->lockForUpdate()
                ->findOrFail($reservation->id);

            if ($reservation->status === InventoryReservation::STATUS_RELEASED) {
                return $reservation->fresh(['lines.catalogItem', 'lines.allocations.lot']);
            }
            if ($reservation->status !== InventoryReservation::STATUS_RESERVED) {
                throw new InvalidArgumentException('Solo una reserva activa puede liberarse.');
            }

            $reservation->load('lines.allocations');
            foreach ($reservation->lines as $line) {
                $item = $this->lockInventoryItem($tenant, (int) $line->catalog_item_id);
                foreach ($line->allocations as $allocation) {
                    $lot = InventoryLot::query()
                        ->where('tenant_id', $tenant->id)
                        ->lockForUpdate()
                        ->findOrFail((int) $allocation->inventory_lot_id);
                    $lot->available_quantity = round((float) $lot->available_quantity + (float) $allocation->quantity, 3);
                    $lot->status = 'active';
                    $lot->save();
                }
                $item->stock_quantity = round((float) $item->stock_quantity + (float) $line->quantity, 3);
                $item->save();
            }

            $reservation->status = InventoryReservation::STATUS_RELEASED;
            $reservation->released_at = now();
            $reservation->save();

            return $reservation->fresh(['lines.catalogItem', 'lines.allocations.lot']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function reverseConfirmedReservation(Tenant $tenant, InventoryReservation $reservation, array $data, ?int $userId = null): InventoryReservation
    {
        return DB::transaction(function () use ($tenant, $reservation, $data, $userId): InventoryReservation {
            $reservation = InventoryReservation::query()
                ->where('tenant_id', $tenant->id)
                ->lockForUpdate()
                ->findOrFail($reservation->id);

            if ($reservation->status === InventoryReservation::STATUS_REVERSED) {
                return $reservation->fresh(['lines.catalogItem', 'lines.allocations.lot']);
            }
            if ($reservation->status !== InventoryReservation::STATUS_CONFIRMED) {
                throw new InvalidArgumentException('Solo una reserva confirmada puede reversarse.');
            }

            $sourceType = $this->blankToNull($data['source_type'] ?? null) ?? 'inventory_reversal';
            $sourceId = $this->blankToNull($data['source_id'] ?? null);
            $sourceNumber = $this->blankToNull($data['source_number'] ?? null) ?? $reservation->source_number;
            $notes = $this->blankToNull($data['notes'] ?? null);

            $reservation->load('lines.allocations');
            foreach ($reservation->lines as $line) {
                $item = $this->lockInventoryItem($tenant, (int) $line->catalog_item_id);
                foreach ($line->allocations as $allocation) {
                    $lot = InventoryLot::query()
                        ->where('tenant_id', $tenant->id)
                        ->lockForUpdate()
                        ->findOrFail((int) $allocation->inventory_lot_id);
                    $lot->available_quantity = round((float) $lot->available_quantity + (float) $allocation->quantity, 3);
                    $lot->status = 'active';
                    $lot->save();

                    $item->stock_quantity = round((float) $item->stock_quantity + (float) $allocation->quantity, 3);
                    $item->save();

                    $this->movement(
                        $tenant,
                        $item,
                        $lot,
                        'entry',
                        'reversal',
                        (float) $allocation->quantity,
                        (float) $allocation->unit_cost,
                        (float) $item->stock_quantity,
                        $sourceType,
                        $sourceId,
                        $sourceNumber,
                        $notes,
                        $userId
                    );
                }
            }

            $reservation->status = InventoryReservation::STATUS_REVERSED;
            $reservation->reversed_at = now();
            $reservation->save();

            return $reservation->fresh(['lines.catalogItem', 'lines.allocations.lot']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function adjust(Tenant $tenant, array $data, ?int $userId = null): InventoryMovement
    {
        return DB::transaction(function () use ($tenant, $data, $userId): InventoryMovement {
            $item = $this->lockInventoryItem($tenant, (int) $data['catalog_item_id']);
            $quantity = $this->positiveQuantity($data['quantity'], 'La cantidad de ajuste debe ser mayor que cero.');
            $direction = (string) $data['direction'];
            $unitCost = isset($data['unit_cost']) ? round((float) $data['unit_cost'], 4) : (float) ($item->reference_cost ?? 0);

            if ($direction === 'entry') {
                $lot = InventoryLot::query()->create([
                    'tenant_id' => $tenant->id,
                    'catalog_item_id' => $item->id,
                    'lot_code' => $this->nextLotCode($tenant, $item, 'AJU'),
                    'received_date' => now()->toDateString(),
                    'unit_cost' => $unitCost,
                    'initial_quantity' => $quantity,
                    'available_quantity' => $quantity,
                    'status' => 'active',
                ]);
                $item->stock_quantity = round((float) $item->stock_quantity + $quantity, 3);
                $reason = 'manual_adjustment';
            } else {
                if ((float) $item->stock_quantity < $quantity) {
                    throw new InvalidArgumentException("Stock insuficiente para {$item->name}.");
                }
                $lot = $this->consumeAdjustmentFromOldestLot($tenant, $item, $quantity);
                $item->stock_quantity = round((float) $item->stock_quantity - $quantity, 3);
                $reason = 'manual_adjustment';
            }

            $item->reference_cost = $this->averageCostForLockedItem($tenant, $item);
            $item->cost_source = 'real';
            $item->save();

            return $this->movement($tenant, $item, $lot, $direction, $reason, $quantity, $unitCost, (float) $item->stock_quantity, 'adjustment', null, null, $this->blankToNull($data['notes'] ?? null), $userId);
        });
    }

    private function lockInventoryItem(Tenant $tenant, int $itemId): CatalogItem
    {
        $item = CatalogItem::query()
            ->where('tenant_id', $tenant->id)
            ->lockForUpdate()
            ->findOrFail($itemId);

        if (! $item->controls_inventory) {
            throw new InvalidArgumentException("{$item->name} no controla inventario.");
        }

        return $item;
    }

    private function movement(Tenant $tenant, CatalogItem $item, ?InventoryLot $lot, string $type, string $reason, float $quantity, ?float $unitCost, ?float $balanceAfter, ?string $referenceType, ?string $referenceId, ?string $referenceNumber, ?string $notes, ?int $userId): InventoryMovement
    {
        return InventoryMovement::query()->create([
            'tenant_id' => $tenant->id,
            'catalog_item_id' => $item->id,
            'inventory_lot_id' => $lot?->id,
            'movement_type' => $type,
            'reason' => $reason,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'balance_after' => $balanceAfter,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'reference_number' => $referenceNumber,
            'notes' => $notes,
            'created_by' => $userId,
        ]);
    }

    private function nextLotCode(Tenant $tenant, CatalogItem $item, string $prefix = 'LOT'): string
    {
        $base = $prefix.'-'.Str::upper(Str::slug((string) ($item->sku ?: $item->id), ''));
        $count = InventoryLot::query()
            ->where('tenant_id', $tenant->id)
            ->where('catalog_item_id', $item->id)
            ->count() + 1;

        do {
            $candidate = $base.'-'.str_pad((string) $count, 4, '0', STR_PAD_LEFT);
            $exists = InventoryLot::query()
                ->where('tenant_id', $tenant->id)
                ->where('lot_code', $candidate)
                ->exists();
            $count++;
        } while ($exists);

        return $candidate;
    }

    private function averageCostForLockedItem(Tenant $tenant, CatalogItem $item): float
    {
        $totals = InventoryLot::query()
            ->where('tenant_id', $tenant->id)
            ->where('catalog_item_id', $item->id)
            ->where('available_quantity', '>', 0)
            ->selectRaw('SUM(available_quantity) as qty, SUM(available_quantity * unit_cost) as cost')
            ->first();

        $qty = (float) ($totals?->qty ?? 0);
        if ($qty <= 0) {
            return 0.0;
        }

        return round((float) ($totals?->cost ?? 0) / $qty, 4);
    }

    private function consumeAdjustmentFromOldestLot(Tenant $tenant, CatalogItem $item, float $quantity): ?InventoryLot
    {
        $remaining = $quantity;
        $firstLot = null;
        $lots = InventoryLot::query()
            ->where('tenant_id', $tenant->id)
            ->where('catalog_item_id', $item->id)
            ->where('available_quantity', '>', 0)
            ->orderByRaw('COALESCE(received_date, DATE(created_at)) asc')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($lots as $lot) {
            if ($remaining <= 0) {
                break;
            }
            $firstLot ??= $lot;
            $take = min($remaining, (float) $lot->available_quantity);
            $lot->available_quantity = round((float) $lot->available_quantity - $take, 3);
            $lot->status = (float) $lot->available_quantity > 0 ? 'active' : 'depleted';
            $lot->save();
            $remaining = round($remaining - $take, 3);
        }

        if ($remaining > 0) {
            throw new InvalidArgumentException("Stock insuficiente para {$item->name}.");
        }

        return $firstLot;
    }

    private function positiveQuantity(mixed $value, string $message): float
    {
        $quantity = round((float) $value, 3);
        if ($quantity <= 0) {
            throw new InvalidArgumentException($message);
        }

        return $quantity;
    }

    private function blankToNull(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}

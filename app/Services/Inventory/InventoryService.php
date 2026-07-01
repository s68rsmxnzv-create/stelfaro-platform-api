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
        $normalized = app(InventoryPurchaseTaxService::class)->normalizePurchase($tenant, $data);

        return DB::transaction(function () use ($tenant, $data, $normalized, $userId): InventoryPurchase {
            $branch = $this->branchScope($data);
            $purchase = InventoryPurchase::query()->create([
                'tenant_id' => $tenant->id,
                'inventory_supplier_id' => $data['inventory_supplier_id'] ?? null,
                ...$branch,
                'purchase_number' => $this->nextPurchaseNumber($tenant),
                'document_type' => $this->blankToNull($normalized['document_type'] ?? null),
                'document_mode' => $normalized['document_mode'],
                'document_number' => $this->blankToNull($data['document_number'] ?? null),
                'payment_condition' => $normalized['payment_condition'],
                'document_total' => $normalized['document_total'],
                'is_consumable' => $normalized['is_consumable'],
                'purchase_date' => $data['purchase_date'],
                'subtotal' => $normalized['subtotal'],
                'tax_amount' => $normalized['tax_amount'],
                'tax_perceived' => $normalized['tax_perceived'],
                'fovial_per_unit' => $normalized['fovial_per_unit'],
                'cotrans_per_unit' => $normalized['cotrans_per_unit'],
                'other_non_taxable_total' => $normalized['other_non_taxable_total'],
                'total' => $normalized['total'],
                'f07_operation_type' => $normalized['f07_operation_type'],
                'f07_classification' => $normalized['f07_classification'],
                'f07_sector' => $normalized['f07_sector'],
                'f07_cost_expense_type' => $normalized['f07_cost_expense_type'],
                'supplier_snapshot' => $data['supplier_snapshot'] ?? null,
                'import_metadata' => $data['import_metadata'] ?? null,
                'status' => 'registered',
                'created_by' => $userId,
            ]);

            foreach ($normalized['lines'] as $line) {
                /** @var CatalogItem $item */
                $item = $line['item'];
                $purchaseLine = InventoryPurchaseLine::query()->create([
                    'tenant_id' => $tenant->id,
                    'inventory_purchase_id' => $purchase->id,
                    'catalog_item_id' => $item->id,
                    'description_snapshot' => $line['description_snapshot'],
                    'unit_code' => $line['unit_code'],
                    'unit_name' => $line['unit_name'],
                    'quantity' => $line['quantity'],
                    'input_unit_cost' => $line['input_unit_cost'],
                    'unit_cost' => $line['unit_cost'],
                    'base_unit_cost' => $line['base_unit_cost'],
                    'tax_unit_amount' => $line['tax_unit_amount'],
                    'tax_rate' => $line['tax_rate'],
                    'total_unit_amount' => $line['total_unit_amount'],
                    'tax_amount' => $line['tax_amount'],
                    'line_total' => $line['line_total'],
                    'price_includes_tax' => $line['price_includes_tax'],
                    'no_inventory' => $line['no_inventory'],
                    'controls_inventory_snapshot' => $line['controls_inventory_snapshot'],
                    'inventory_quantity' => $line['inventory_quantity'],
                ]);

                if ($line['no_inventory']) {
                    continue;
                }

                $item = $this->lockInventoryItem($tenant, (int) $line['catalog_item_id']);
                $lot = InventoryLot::query()->create([
                    'tenant_id' => $tenant->id,
                    ...$branch,
                    'catalog_item_id' => $item->id,
                    'inventory_supplier_id' => $purchase->inventory_supplier_id,
                    'inventory_purchase_id' => $purchase->id,
                    'inventory_purchase_line_id' => $purchaseLine->id,
                    'lot_code' => $this->nextLotCode($tenant, $item),
                    'received_date' => $purchase->purchase_date,
                    'unit_cost' => $line['unit_cost'],
                    'initial_quantity' => $line['inventory_quantity'],
                    'available_quantity' => $line['inventory_quantity'],
                    'status' => 'active',
                ]);

                $item->stock_quantity = round((float) $item->stock_quantity + (float) $line['inventory_quantity'], 3);
                $item->cost_source = 'real';
                $item->reference_cost = $this->averageCostForLockedItem($tenant, $item);
                $item->save();

                $branchBalance = $this->availableQuantityForItem($tenant, $item, $branch['core_sucursal_id']);
                $this->movement($tenant, $branch, $item, $lot, 'entry', 'purchase', (float) $line['inventory_quantity'], (float) $line['unit_cost'], $branchBalance, 'purchase', (string) $purchase->id, $purchase->document_number, $line['description_snapshot'], $userId);
            }

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
            $branch = $this->branchScope($data);
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
                ...$branch,
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
                $available = $this->availableQuantityForItem($tenant, $item, $branch['core_sucursal_id']);
                if ($available < $quantity) {
                    throw new InvalidArgumentException($this->insufficientStockMessage($tenant, $item, $quantity, $available, $branch));
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
                    ->when(
                        $branch['core_sucursal_id'] !== null,
                        fn ($query) => $query->where('core_sucursal_id', $branch['core_sucursal_id']),
                        fn ($query) => $query->whereNull('core_sucursal_id')
                    )
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
                        ...$branch,
                        'inventory_reservation_line_id' => $lineModel->id,
                        'catalog_item_id' => $item->id,
                        'inventory_lot_id' => $lot->id,
                        'quantity' => $take,
                        'unit_cost' => $lot->unit_cost,
                    ]);

                    $remaining = round($remaining - $take, 3);
                }

                if ($remaining > 0) {
                    $available = $this->availableQuantityForItem($tenant, $item, $branch['core_sucursal_id']);
                    throw new InvalidArgumentException($this->insufficientStockMessage($tenant, $item, (float) $lineModel->quantity, $available, $branch));
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
                        [
                            'core_sucursal_id' => $reservation->core_sucursal_id,
                            'core_sucursal_code' => $reservation->core_sucursal_code,
                            'core_sucursal_name' => $reservation->core_sucursal_name,
                        ],
                        $item,
                        $allocation->lot,
                        'exit',
                        'sale',
                        (float) $allocation->quantity,
                        (float) $allocation->unit_cost,
                        $this->availableQuantityForItem($tenant, $item, $reservation->core_sucursal_id),
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
                        [
                            'core_sucursal_id' => $reservation->core_sucursal_id,
                            'core_sucursal_code' => $reservation->core_sucursal_code,
                            'core_sucursal_name' => $reservation->core_sucursal_name,
                        ],
                        $item,
                        $lot,
                        'entry',
                        'reversal',
                        (float) $allocation->quantity,
                        (float) $allocation->unit_cost,
                        $this->availableQuantityForItem($tenant, $item, $reservation->core_sucursal_id),
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
            $branch = $this->branchScope($data);
            $quantity = $this->positiveQuantity($data['quantity'], 'La cantidad de ajuste debe ser mayor que cero.');
            $direction = (string) $data['direction'];
            $unitCost = isset($data['unit_cost']) ? round((float) $data['unit_cost'], 4) : (float) ($item->reference_cost ?? 0);

            if ($direction === 'entry') {
                $lot = InventoryLot::query()->create([
                    'tenant_id' => $tenant->id,
                    ...$branch,
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
                $available = $this->availableQuantityForItem($tenant, $item, $branch['core_sucursal_id']);
                if ($available < $quantity) {
                    throw new InvalidArgumentException($this->insufficientStockMessage($tenant, $item, $quantity, $available, $branch));
                }
                $lot = $this->consumeAdjustmentFromOldestLot($tenant, $item, $quantity, $branch['core_sucursal_id']);
                $item->stock_quantity = round((float) $item->stock_quantity - $quantity, 3);
                $reason = 'manual_adjustment';
            }

            $item->reference_cost = $this->averageCostForLockedItem($tenant, $item);
            $item->cost_source = 'real';
            $item->save();

            return $this->movement($tenant, $branch, $item, $lot, $direction, $reason, $quantity, $unitCost, $this->availableQuantityForItem($tenant, $item, $branch['core_sucursal_id']), 'adjustment', null, null, $this->blankToNull($data['notes'] ?? null), $userId);
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

    /**
     * @param  array{core_sucursal_id:int|null,core_sucursal_code:string|null,core_sucursal_name:string|null}  $branch
     */
    private function movement(Tenant $tenant, array $branch, CatalogItem $item, ?InventoryLot $lot, string $type, string $reason, float $quantity, ?float $unitCost, ?float $balanceAfter, ?string $referenceType, ?string $referenceId, ?string $referenceNumber, ?string $notes, ?int $userId): InventoryMovement
    {
        return InventoryMovement::query()->create([
            'tenant_id' => $tenant->id,
            ...$branch,
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

    private function nextPurchaseNumber(Tenant $tenant): int
    {
        return (int) InventoryPurchase::query()
            ->where('tenant_id', $tenant->id)
            ->lockForUpdate()
            ->max('purchase_number') + 1;
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

    private function consumeAdjustmentFromOldestLot(Tenant $tenant, CatalogItem $item, float $quantity, ?int $branchId): ?InventoryLot
    {
        $remaining = $quantity;
        $firstLot = null;
        $lots = InventoryLot::query()
            ->where('tenant_id', $tenant->id)
            ->where('catalog_item_id', $item->id)
            ->when(
                $branchId !== null,
                fn ($query) => $query->where('core_sucursal_id', $branchId),
                fn ($query) => $query->whereNull('core_sucursal_id')
            )
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
            $available = $this->availableQuantityForItem($tenant, $item, $branchId);
            throw new InvalidArgumentException($this->insufficientStockMessage($tenant, $item, $quantity, $available, [
                'core_sucursal_id' => $branchId,
                'core_sucursal_code' => null,
                'core_sucursal_name' => null,
            ]));
        }

        return $firstLot;
    }

    private function availableQuantityForItem(Tenant $tenant, CatalogItem $item, ?int $branchId): float
    {
        return round((float) InventoryLot::query()
            ->where('tenant_id', $tenant->id)
            ->where('catalog_item_id', $item->id)
            ->when(
                $branchId !== null,
                fn ($query) => $query->where('core_sucursal_id', $branchId),
                fn ($query) => $query->whereNull('core_sucursal_id')
            )
            ->where('available_quantity', '>', 0)
            ->sum('available_quantity'), 3);
    }

    /**
     * @param  array{core_sucursal_id:int|null,core_sucursal_code:string|null,core_sucursal_name:string|null}  $branch
     */
    private function insufficientStockMessage(Tenant $tenant, CatalogItem $item, float $needed, float $available, array $branch): string
    {
        $branchLabel = $branch['core_sucursal_code'] ?: $branch['core_sucursal_name'] ?: 'la sucursal seleccionada';
        $otherStock = InventoryLot::query()
            ->where('tenant_id', $tenant->id)
            ->where('catalog_item_id', $item->id)
            ->where('available_quantity', '>', 0)
            ->when(
                $branch['core_sucursal_id'] !== null,
                fn ($query) => $query->where(function ($inner) use ($branch): void {
                    $inner->where('core_sucursal_id', '!=', $branch['core_sucursal_id'])
                        ->orWhereNull('core_sucursal_id');
                }),
                fn ($query) => $query->whereNotNull('core_sucursal_id')
            )
            ->selectRaw("COALESCE(core_sucursal_code, core_sucursal_name, 'Sin asignar') as branch_label, SUM(available_quantity) as qty")
            ->groupBy('branch_label')
            ->orderByDesc('qty')
            ->limit(3)
            ->get()
            ->map(fn ($row): string => "{$row->branch_label}: ".round((float) $row->qty, 3))
            ->implode(', ');

        $message = "Stock insuficiente para {$item->name} en {$branchLabel}. Disponible: {$available}; requerido: {$needed}.";
        if ($otherStock !== '') {
            $message .= " En otras sucursales: {$otherStock}.";
        }

        return $message;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{core_sucursal_id:int|null,core_sucursal_code:string|null,core_sucursal_name:string|null}
     */
    private function branchScope(array $data): array
    {
        $branchId = isset($data['core_sucursal_id']) && $data['core_sucursal_id'] !== ''
            ? (int) $data['core_sucursal_id']
            : null;

        return [
            'core_sucursal_id' => $branchId && $branchId > 0 ? $branchId : null,
            'core_sucursal_code' => $this->blankToNull($data['core_sucursal_code'] ?? null),
            'core_sucursal_name' => $this->blankToNull($data['core_sucursal_name'] ?? null),
        ];
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

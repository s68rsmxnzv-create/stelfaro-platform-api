<?php

namespace App\Services\Inventory;

use App\Models\CatalogItem;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryPhysicalCount;
use App\Models\InventoryPhysicalCountLine;
use App\Models\InventoryPurchase;
use App\Models\InventoryPurchaseLine;
use App\Models\InventoryReservation;
use App\Models\InventoryReservationLine;
use App\Models\InventorySale;
use App\Models\InventorySaleAllocation;
use App\Models\InventorySaleLine;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferLine;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class InventoryService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function recordSale(Tenant $tenant, array $data, ?int $userId = null): InventorySale
    {
        return DB::transaction(function () use ($tenant, $data, $userId): InventorySale {
            $sourceType = $this->blankToNull($data['source_type'] ?? null) ?? 'dte';
            $sourceId = $this->blankToNull($data['source_id'] ?? null);
            if (! $sourceId) {
                throw new InvalidArgumentException('La venta necesita identificador de DTE.');
            }

            $existing = InventorySale::query()
                ->where('tenant_id', $tenant->id)
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $financial = $this->saleFinancials($data);
                $shouldRefreshLineFinancials = $existing->fiscal_document_type === null
                    && $financial['fiscal_document_type'] !== null;
                $existing->forceFill([
                    'source_number' => $this->blankToNull($data['source_number'] ?? null) ?? $existing->source_number,
                    ...$financial,
                    'metadata' => array_merge($existing->metadata ?? [], $data['metadata'] ?? []),
                ])->save();
                if ($shouldRefreshLineFinancials) {
                    $this->refreshSaleLineFinancials($existing, $data['lines']);
                }
                $fresh = $existing->fresh(['lines.catalogItem']);
                $fresh->wasRecentlyCreated = false;

                return $fresh;
            }

            $replacementSourceType = $this->blankToNull($data['replacement_of_source_type'] ?? null);
            $replacementSourceId = $this->blankToNull($data['replacement_of_source_id'] ?? null);
            $replacementOf = null;
            $inheritedLines = collect();
            $confirmedByItem = collect();

            if ($replacementSourceId) {
                $replacementOf = InventorySale::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('source_type', $replacementSourceType ?? 'dte')
                    ->where('source_id', $replacementSourceId)
                    ->where('status', 'active')
                    ->with('lines')
                    ->lockForUpdate()
                    ->first();

                if (! $replacementOf) {
                    throw new InvalidArgumentException('No encontramos la venta activa del DTE que deseas sustituir.');
                }

                $anotherReplacement = InventorySale::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('replacement_of_sale_id', $replacementOf->id)
                    ->whereIn('status', ['pending_replacement', 'active'])
                    ->lockForUpdate()
                    ->exists();

                if ($anotherReplacement) {
                    throw new InvalidArgumentException('Ese DTE ya tiene un sustituto pendiente o activo.');
                }

                $inheritedLines = $replacementOf->lines->keyBy('id');
                $confirmedReservation = InventoryReservation::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('source_type', $replacementOf->source_type)
                    ->where('source_id', $replacementOf->source_id)
                    ->where('status', InventoryReservation::STATUS_CONFIRMED)
                    ->with('lines')
                    ->lockForUpdate()
                    ->first();
                $confirmedByItem = $confirmedReservation?->lines
                    ->groupBy('catalog_item_id')
                    ->map(fn ($lines) => round((float) $lines->sum('quantity'), 3)) ?? collect();
            }

            $inheritedBySourceLine = [];
            $inheritedByItem = [];
            foreach ($data['lines'] as $line) {
                $inheritedQuantity = round((float) ($line['inherited_quantity'] ?? 0), 3);
                if ($inheritedQuantity <= 0) {
                    continue;
                }

                if (! $replacementOf) {
                    throw new InvalidArgumentException('La herencia de inventario requiere un DTE original.');
                }

                $sourceLineId = (int) ($line['inherited_from_line_id'] ?? 0);
                /** @var InventorySaleLine|null $sourceLine */
                $sourceLine = $inheritedLines->get($sourceLineId);
                $itemId = (int) ($line['catalog_item_id'] ?? 0);
                if (! $sourceLine || $sourceLine->line_origin !== 'inventory' || (int) $sourceLine->catalog_item_id !== $itemId) {
                    throw new InvalidArgumentException('La línea heredada no corresponde al inventario del DTE original.');
                }

                if ($inheritedQuantity > round((float) ($line['quantity'] ?? 0), 3)) {
                    throw new InvalidArgumentException('La cantidad heredada no puede superar la cantidad del sustituto.');
                }

                $inheritedBySourceLine[$sourceLineId] = round((float) ($inheritedBySourceLine[$sourceLineId] ?? 0) + $inheritedQuantity, 3);
                if ($inheritedBySourceLine[$sourceLineId] > (float) $sourceLine->quantity) {
                    throw new InvalidArgumentException('La cantidad heredada supera la salida registrada en el DTE original.');
                }

                $inheritedByItem[$itemId] = round((float) ($inheritedByItem[$itemId] ?? 0) + $inheritedQuantity, 3);
                if ($inheritedByItem[$itemId] > (float) ($confirmedByItem->get($itemId) ?? 0)) {
                    throw new InvalidArgumentException('El DTE original no tiene una reserva confirmada suficiente para heredar esa cantidad.');
                }
            }

            $branch = $this->branchScope($data);
            $financial = $this->saleFinancials($data);
            if ($replacementOf && $replacementOf->core_sucursal_id !== null && $branch['core_sucursal_id'] !== $replacementOf->core_sucursal_id) {
                throw new InvalidArgumentException('El sustituto debe conservar la sucursal de la salida original.');
            }
            $sale = InventorySale::query()->create([
                'tenant_id' => $tenant->id,
                ...$branch,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'source_number' => $this->blankToNull($data['source_number'] ?? null),
                'sale_date' => $data['sale_date'] ?? now()->toDateString(),
                ...$financial,
                'status' => $replacementOf ? 'pending_replacement' : 'active',
                'replacement_of_sale_id' => $replacementOf?->id,
                'metadata' => $data['metadata'] ?? null,
                'created_by' => $userId,
            ]);

            foreach ($data['lines'] as $line) {
                $item = null;
                if (! empty($line['catalog_item_id'])) {
                    $item = CatalogItem::query()
                        ->where('tenant_id', $tenant->id)
                        ->find((int) $line['catalog_item_id']);
                }

                InventorySaleLine::query()->create([
                    'tenant_id' => $tenant->id,
                    'inventory_sale_id' => $sale->id,
                    'catalog_item_id' => $item?->id,
                    'line_origin' => $this->blankToNull($line['line_origin'] ?? null) ?? ($item ? ($item->controls_inventory ? 'inventory' : 'catalog') : 'free'),
                    'inherited_from_line_id' => ! empty($line['inherited_from_line_id']) ? (int) $line['inherited_from_line_id'] : null,
                    'description_snapshot' => $this->blankToNull($line['description'] ?? null) ?? $item?->name,
                    'quantity' => $this->positiveQuantity($line['quantity'], 'La cantidad vendida debe ser mayor que cero.'),
                    'inherited_quantity' => round((float) ($line['inherited_quantity'] ?? 0), 3),
                    'unit_price' => round((float) ($line['unit_price'] ?? 0), 4),
                    'discount_amount' => round((float) ($line['discount_amount'] ?? 0), 2),
                    'net_total' => round((float) ($line['net_total'] ?? 0), 2),
                    'tax_amount' => round((float) ($line['tax_amount'] ?? 0), 2),
                    'total_amount' => round((float) ($line['total_amount'] ?? (($line['net_total'] ?? 0) + ($line['tax_amount'] ?? 0))), 2),
                    'reference_unit_cost' => round((float) ($line['reference_unit_cost'] ?? $item?->reference_cost ?? 0), 4),
                ]);
            }

            $fresh = $sale->fresh(['lines.catalogItem']);
            $fresh->wasRecentlyCreated = true;

            return $fresh;
        });
    }

    /**
     * @return array{sale:InventorySale,reservation:InventoryReservation|null}
     */
    public function saleFulfillment(Tenant $tenant, string $sourceType, string $sourceId): array
    {
        $sale = InventorySale::query()
            ->where('tenant_id', $tenant->id)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('status', 'active')
            ->with(['lines.catalogItem'])
            ->first();

        if (! $sale) {
            throw new InvalidArgumentException('No encontramos una venta activa vinculada al DTE original.');
        }

        $reservation = InventoryReservation::query()
            ->where('tenant_id', $tenant->id)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('status', InventoryReservation::STATUS_CONFIRMED)
            ->with(['lines.catalogItem', 'lines.allocations.lot'])
            ->first();

        return ['sale' => $sale, 'reservation' => $reservation];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{original:InventorySale,replacement:InventorySale}
     */
    public function supersedeDteSale(Tenant $tenant, array $data): array
    {
        return DB::transaction(function () use ($tenant, $data): array {
            $sourceType = $this->blankToNull($data['source_type'] ?? null) ?? 'dte';
            $original = InventorySale::query()
                ->where('tenant_id', $tenant->id)
                ->where('source_type', $sourceType)
                ->where('source_id', (string) $data['original_source_id'])
                ->lockForUpdate()
                ->first();
            $replacement = InventorySale::query()
                ->where('tenant_id', $tenant->id)
                ->where('source_type', $sourceType)
                ->where('source_id', (string) $data['replacement_source_id'])
                ->lockForUpdate()
                ->first();

            if (! $original || ! $replacement || (int) $replacement->replacement_of_sale_id !== (int) $original->id) {
                throw new InvalidArgumentException('No encontramos una sustitución comercial válida entre ambos DTE.');
            }

            if ($original->status === 'superseded' && $replacement->status === 'active') {
                return [
                    'original' => $original->fresh(['lines.catalogItem']),
                    'replacement' => $replacement->fresh(['lines.catalogItem']),
                ];
            }

            if ($original->status !== 'active' || $replacement->status !== 'pending_replacement') {
                throw new InvalidArgumentException('La sustitución comercial ya no está pendiente.');
            }

            $eventMetadata = array_filter([
                'invalidation_event_id' => $data['event_id'] ?? null,
                'invalidation_event_number' => $data['event_number'] ?? null,
            ], fn ($value) => $value !== null && $value !== '');

            $original->forceFill([
                'status' => 'superseded',
                'superseded_at' => now(),
                'metadata' => array_merge($original->metadata ?? [], $eventMetadata, [
                    'replacement_sale_id' => $replacement->id,
                    'replacement_source_id' => $replacement->source_id,
                ]),
            ])->save();
            $replacement->forceFill([
                'status' => 'active',
                'metadata' => array_merge($replacement->metadata ?? [], $eventMetadata, [
                    'supersedes_sale_id' => $original->id,
                    'supersedes_source_id' => $original->source_id,
                ]),
            ])->save();

            return [
                'original' => $original->fresh(['lines.catalogItem']),
                'replacement' => $replacement->fresh(['lines.catalogItem']),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{sale:InventorySale|null,reservation:InventoryReservation|null}
     */
    public function reverseDteSale(Tenant $tenant, array $data, ?int $userId = null): array
    {
        return DB::transaction(function () use ($tenant, $data, $userId): array {
            $sourceId = $this->blankToNull($data['source_id'] ?? null);
            if (! $sourceId) {
                throw new InvalidArgumentException('La reversa necesita identificador del DTE invalidado.');
            }

            $sourceType = $this->blankToNull($data['source_type'] ?? null) ?? 'dte';
            $eventId = $this->blankToNull($data['event_id'] ?? null);
            $eventNumber = $this->blankToNull($data['event_number'] ?? null);

            $sale = InventorySale::query()
                ->where('tenant_id', $tenant->id)
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->lockForUpdate()
                ->first();

            if ($sale && $sale->status !== 'reversed') {
                $sale->status = 'reversed';
                $sale->reversed_at = now();
                $sale->save();
            }

            $reservation = InventoryReservation::query()
                ->where('tenant_id', $tenant->id)
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->lockForUpdate()
                ->first();

            if ($reservation && $reservation->status === InventoryReservation::STATUS_CONFIRMED) {
                $reservation = $this->reverseConfirmedReservation($tenant, $reservation, [
                    'source_type' => 'mh_invalidation',
                    'source_id' => $eventId,
                    'source_number' => $eventNumber,
                    'notes' => $this->blankToNull($data['notes'] ?? null) ?? 'Reversa automática por invalidación tipo 2 aceptada por MH.',
                ], $userId);
            }

            return [
                'sale' => $sale?->fresh(['lines.catalogItem']),
                'reservation' => $reservation?->fresh(['lines.catalogItem', 'lines.allocations.lot']),
            ];
        });
    }

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
                'fiscal_annex_eligible' => (bool) ($data['fiscal_annex_eligible'] ?? true),
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
            $movementReason = $this->blankToNull($data['movement_reason'] ?? null) ?? 'sale';

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
                        $movementReason,
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
            $movementReason = $this->blankToNull($data['movement_reason'] ?? null) ?? 'reversal';

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
                        $movementReason,
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
                $reason = $this->blankToNull($data['reason'] ?? null) ?? 'manual_adjustment';
            } else {
                $available = $this->availableQuantityForItem($tenant, $item, $branch['core_sucursal_id']);
                if ($available < $quantity) {
                    throw new InvalidArgumentException($this->insufficientStockMessage($tenant, $item, $quantity, $available, $branch));
                }
                $lot = $this->consumeAdjustmentFromOldestLot($tenant, $item, $quantity, $branch['core_sucursal_id']);
                $item->stock_quantity = round((float) $item->stock_quantity - $quantity, 3);
                $reason = $this->blankToNull($data['reason'] ?? null) ?? 'manual_adjustment';
            }

            $item->reference_cost = $this->averageCostForLockedItem($tenant, $item);
            $item->cost_source = 'real';
            $item->save();

            return $this->movement($tenant, $branch, $item, $lot, $direction, $reason, $quantity, $unitCost, $this->availableQuantityForItem($tenant, $item, $branch['core_sucursal_id']), 'adjustment', null, null, $this->blankToNull($data['notes'] ?? null), $userId);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function physicalCount(Tenant $tenant, array $data, ?int $userId = null): InventoryPhysicalCount
    {
        return DB::transaction(function () use ($tenant, $data, $userId): InventoryPhysicalCount {
            $branch = $this->branchScope($data);
            $count = InventoryPhysicalCount::query()->create([
                'tenant_id' => $tenant->id,
                ...$branch,
                'count_date' => $data['count_date'] ?? now()->toDateString(),
                'status' => 'applied',
                'notes' => $this->blankToNull($data['notes'] ?? null),
                'created_by' => $userId,
            ]);

            foreach ($data['lines'] as $line) {
                $item = $this->lockInventoryItem($tenant, (int) $line['catalog_item_id']);
                $system = $this->availableQuantityForItem($tenant, $item, $branch['core_sucursal_id']);
                $counted = round((float) $line['counted_quantity'], 3);
                $difference = round($counted - $system, 3);

                InventoryPhysicalCountLine::query()->create([
                    'tenant_id' => $tenant->id,
                    'inventory_physical_count_id' => $count->id,
                    'catalog_item_id' => $item->id,
                    'system_quantity' => $system,
                    'counted_quantity' => $counted,
                    'difference_quantity' => $difference,
                ]);

                if ($difference > 0) {
                    $this->adjust($tenant, [
                        ...$branch,
                        'catalog_item_id' => $item->id,
                        'direction' => 'entry',
                        'quantity' => $difference,
                        'unit_cost' => $item->reference_cost,
                        'reason' => 'physical_count',
                        'notes' => 'Conteo físico #'.$count->id,
                    ], $userId);
                } elseif ($difference < 0) {
                    $this->adjust($tenant, [
                        ...$branch,
                        'catalog_item_id' => $item->id,
                        'direction' => 'exit',
                        'quantity' => abs($difference),
                        'unit_cost' => $item->reference_cost,
                        'reason' => 'physical_count',
                        'notes' => 'Conteo físico #'.$count->id,
                    ], $userId);
                }
            }

            return $count->fresh(['lines.catalogItem']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function transfer(Tenant $tenant, array $data, ?int $userId = null): InventoryTransfer
    {
        return DB::transaction(function () use ($tenant, $data, $userId): InventoryTransfer {
            $from = $this->branchScope([
                'core_sucursal_id' => $data['from_core_sucursal_id'],
                'core_sucursal_code' => $data['from_core_sucursal_code'] ?? null,
                'core_sucursal_name' => $data['from_core_sucursal_name'] ?? null,
            ]);
            $to = $this->branchScope([
                'core_sucursal_id' => $data['to_core_sucursal_id'],
                'core_sucursal_code' => $data['to_core_sucursal_code'] ?? null,
                'core_sucursal_name' => $data['to_core_sucursal_name'] ?? null,
            ]);

            if ($from['core_sucursal_id'] === null || $to['core_sucursal_id'] === null || $from['core_sucursal_id'] === $to['core_sucursal_id']) {
                throw new InvalidArgumentException('Selecciona sucursales origen y destino diferentes.');
            }

            $transfer = InventoryTransfer::query()->create([
                'tenant_id' => $tenant->id,
                'from_core_sucursal_id' => $from['core_sucursal_id'],
                'from_core_sucursal_code' => $from['core_sucursal_code'],
                'from_core_sucursal_name' => $from['core_sucursal_name'],
                'to_core_sucursal_id' => $to['core_sucursal_id'],
                'to_core_sucursal_code' => $to['core_sucursal_code'],
                'to_core_sucursal_name' => $to['core_sucursal_name'],
                'transfer_number' => $this->nextTransferNumber($tenant),
                'transfer_date' => $data['transfer_date'] ?? now()->toDateString(),
                'status' => 'applied',
                'notes' => $this->blankToNull($data['notes'] ?? null),
                'created_by' => $userId,
            ]);

            foreach ($data['lines'] as $line) {
                $item = $this->lockInventoryItem($tenant, (int) $line['catalog_item_id']);
                $quantity = $this->positiveQuantity($line['quantity'], 'La cantidad a transferir debe ser mayor que cero.');
                $available = $this->availableQuantityForItem($tenant, $item, $from['core_sucursal_id']);
                if ($available < $quantity) {
                    throw new InvalidArgumentException($this->insufficientStockMessage($tenant, $item, $quantity, $available, $from));
                }

                $remaining = $quantity;
                $weightedCost = 0.0;
                $lots = InventoryLot::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('catalog_item_id', $item->id)
                    ->where('core_sucursal_id', $from['core_sucursal_id'])
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
                    $lot->available_quantity = round((float) $lot->available_quantity - $take, 3);
                    $lot->status = (float) $lot->available_quantity > 0 ? 'active' : 'depleted';
                    $lot->save();

                    $destinationLot = InventoryLot::query()->create([
                        'tenant_id' => $tenant->id,
                        ...$to,
                        'catalog_item_id' => $item->id,
                        'lot_code' => $this->nextLotCode($tenant, $item, 'TRF'),
                        'received_date' => $transfer->transfer_date,
                        'unit_cost' => $lot->unit_cost,
                        'initial_quantity' => $take,
                        'available_quantity' => $take,
                        'status' => 'active',
                    ]);

                    $weightedCost += $take * (float) $lot->unit_cost;
                    $remaining = round($remaining - $take, 3);

                    $this->movement($tenant, $from, $item, $lot, 'exit', 'transfer_out', $take, (float) $lot->unit_cost, $this->availableQuantityForItem($tenant, $item, $from['core_sucursal_id']), 'transfer', (string) $transfer->id, $transfer->transfer_number, $transfer->notes, $userId);
                    $this->movement($tenant, $to, $item, $destinationLot, 'entry', 'transfer_in', $take, (float) $lot->unit_cost, $this->availableQuantityForItem($tenant, $item, $to['core_sucursal_id']), 'transfer', (string) $transfer->id, $transfer->transfer_number, $transfer->notes, $userId);
                }

                InventoryTransferLine::query()->create([
                    'tenant_id' => $tenant->id,
                    'inventory_transfer_id' => $transfer->id,
                    'catalog_item_id' => $item->id,
                    'quantity' => $quantity,
                    'unit_cost' => $quantity > 0 ? round($weightedCost / $quantity, 4) : 0,
                ]);
            }

            return $transfer->fresh(['lines.catalogItem']);
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
        Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();

        return (int) InventoryPurchase::query()
            ->where('tenant_id', $tenant->id)
            ->max('purchase_number') + 1;
    }

    private function nextTransferNumber(Tenant $tenant): string
    {
        return 'TRF-'.str_pad((string) ((int) InventoryTransfer::query()->where('tenant_id', $tenant->id)->count() + 1), 8, '0', STR_PAD_LEFT);
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

    /**
     * @param  array<string, mixed>  $data
     * @return array{operation_kind:string,fiscal_document_type:?string,reporting_sign:int,net_amount:float,tax_amount:float,total_amount:float}
     */
    private function saleFinancials(array $data): array
    {
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $rawType = $data['fiscal_document_type'] ?? $metadata['document_type'] ?? null;
        $documentType = $this->blankToNull($rawType);
        $documentType = $documentType !== null ? str_pad($documentType, 2, '0', STR_PAD_LEFT) : null;
        $kind = match ($documentType) {
            '05' => 'credit_note',
            '06' => 'debit_note',
            '14' => 'excluded_subject_purchase',
            default => 'sale',
        };
        $sign = $documentType === '05' ? -1 : ($documentType === '14' ? 0 : 1);
        $lines = is_array($data['lines'] ?? null) ? $data['lines'] : [];
        $net = round((float) ($data['net_amount'] ?? collect($lines)->sum(fn (array $line): float => (float) ($line['net_total'] ?? 0))), 2);
        $tax = round((float) ($data['tax_amount'] ?? collect($lines)->sum(fn (array $line): float => (float) ($line['tax_amount'] ?? 0))), 2);
        $lineTotal = collect($lines)->sum(
            fn (array $line): float => (float) ($line['total_amount'] ?? (($line['net_total'] ?? 0) + ($line['tax_amount'] ?? 0)))
        );
        $total = round((float) ($data['total_amount'] ?? $lineTotal), 2);

        return [
            'operation_kind' => $kind,
            'fiscal_document_type' => $documentType,
            'reporting_sign' => $sign,
            'net_amount' => max(0, $net),
            'tax_amount' => max(0, $tax),
            'total_amount' => max(0, $total),
        ];
    }

    /** @param array<int, array<string, mixed>> $incoming */
    private function refreshSaleLineFinancials(InventorySale $sale, array $incoming): void
    {
        $stored = $sale->lines()->orderBy('id')->get();
        if ($stored->count() !== count($incoming)) {
            throw new InvalidArgumentException('El detalle fiscal no coincide con las líneas de la venta comercial existente.');
        }

        foreach ($stored->values() as $index => $line) {
            $data = $incoming[$index];
            $line->forceFill([
                'unit_price' => round((float) ($data['unit_price'] ?? $line->unit_price), 4),
                'discount_amount' => round((float) ($data['discount_amount'] ?? $line->discount_amount), 2),
                'net_total' => round((float) ($data['net_total'] ?? $line->net_total), 2),
                'tax_amount' => round((float) ($data['tax_amount'] ?? 0), 2),
                'total_amount' => round((float) ($data['total_amount'] ?? (($data['net_total'] ?? $line->net_total) + ($data['tax_amount'] ?? 0))), 2),
            ])->save();
        }
    }
}

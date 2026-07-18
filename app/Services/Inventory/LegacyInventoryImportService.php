<?php

namespace App\Services\Inventory;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LegacyInventoryImportService
{
    /** @var list<string> */
    public const TABLES = [
        'catalog_categories',
        'catalog_items',
        'inventory_suppliers',
        'inventory_purchases',
        'inventory_purchase_lines',
        'inventory_lots',
        'inventory_movements',
        'inventory_reservations',
        'inventory_reservation_lines',
        'inventory_sale_allocations',
        'inventory_sales',
        'inventory_sale_lines',
        'inventory_physical_counts',
        'inventory_physical_count_lines',
        'inventory_transfers',
        'inventory_transfer_lines',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, int|float>
     */
    public function analyze(array $payload): array
    {
        foreach (['categories', 'suppliers', 'items', 'purchases', 'purchase_lines', 'lots', 'movements'] as $key) {
            if (! isset($payload[$key]) || ! is_array($payload[$key])) {
                throw new InvalidArgumentException("El archivo legado no contiene {$key}.");
            }
        }

        $categoryIds = $this->ids($payload['categories'], 'id', 'categorías');
        $supplierIds = $this->ids($payload['suppliers'], 'id', 'proveedores');
        $itemIds = $this->ids($payload['items'], 'id', 'productos');
        $purchaseIds = $this->ids($payload['purchases'], 'id', 'compras');
        $lineIds = $this->ids($payload['purchase_lines'], 'id', 'partidas');
        $this->ids($payload['movements'], 'id', 'movimientos');
        $branch = $this->branch($payload);

        foreach ($payload['items'] as $item) {
            $this->references($item['categoria_item_id'] ?? null, $categoryIds, 'categoría de producto');
            $this->references($item['proveedor_id'] ?? null, $supplierIds, 'proveedor de producto');
        }
        foreach ($payload['purchases'] as $purchase) {
            $this->references($purchase['proveedor_id'] ?? null, $supplierIds, 'proveedor de compra');
        }
        foreach ($payload['purchase_lines'] as $line) {
            $this->references($line['compra_id'] ?? null, $purchaseIds, 'compra de partida', false);
            $this->references($line['item_id'] ?? null, $itemIds, 'producto de partida', false);
            if ((float) ($line['cantidad'] ?? 0) <= 0) {
                throw new InvalidArgumentException('Todas las partidas deben tener una cantidad mayor que cero.');
            }
        }
        foreach ($payload['lots'] as $lot) {
            $this->references($lot['item_id'] ?? null, $itemIds, 'producto de lote', false);
            $this->references($lot['proveedor_id'] ?? null, $supplierIds, 'proveedor de lote');
            $this->references($lot['compra_id'] ?? null, $purchaseIds, 'compra de lote');
            $this->references($lot['compra_detalle_id'] ?? null, $lineIds, 'partida de lote');
        }
        foreach ($payload['movements'] as $movement) {
            $this->references($movement['item_id'] ?? null, $itemIds, 'producto de movimiento', false);
            if ((float) ($movement['cantidad'] ?? 0) <= 0) {
                throw new InvalidArgumentException('Todos los movimientos deben tener una cantidad mayor que cero.');
            }
            if (! in_array($movement['tipo_movimiento'] ?? null, ['entrada', 'salida'], true)) {
                throw new InvalidArgumentException('El kardex contiene un tipo de movimiento desconocido.');
            }
        }

        $itemStock = collect($payload['items'])->sum(fn (array $item): float => (float) ($item['stock_actual'] ?? 0));
        $availableStock = collect($payload['lots'])->sum(fn (array $lot): float => (float) ($lot['cantidad_disponible'] ?? 0));
        $lotBalances = collect($payload['lots'])->groupBy('item_id')->map(fn ($lots): float => $lots->sum(fn (array $lot): float => (float) ($lot['cantidad_disponible'] ?? 0)));
        $movementBalances = [];
        $negativeMovementBalances = 0;
        foreach ($this->orderedMovements($payload['movements']) as $movement) {
            $itemId = (int) $movement['item_id'];
            $quantity = (float) $movement['cantidad'];
            $movementBalances[$itemId] = ($movementBalances[$itemId] ?? 0) + (($movement['tipo_movimiento'] ?? null) === 'entrada' ? $quantity : -$quantity);
            $negativeMovementBalances += $movementBalances[$itemId] < -0.001 ? 1 : 0;
        }
        $stockMismatchItems = 0;
        $stockAbsoluteDifference = 0.0;
        $movementMismatchItems = 0;
        $movementStockDifference = 0.0;
        foreach ($payload['items'] as $item) {
            $itemId = (int) $item['id'];
            $stock = (float) ($item['stock_actual'] ?? 0);
            $lotDifference = abs($stock - (float) $lotBalances->get($itemId, 0));
            $movementDifference = abs($stock - ($movementBalances[$itemId] ?? 0));
            $stockMismatchItems += $lotDifference > 0.001 ? 1 : 0;
            $stockAbsoluteDifference += $lotDifference;
            $movementMismatchItems += $movementDifference > 0.001 ? 1 : 0;
            $movementStockDifference += $movementDifference;
        }

        return [
            'categories' => count($payload['categories']),
            'suppliers' => count($payload['suppliers']),
            'items' => count($payload['items']),
            'purchases' => count($payload['purchases']),
            'purchase_lines' => count($payload['purchase_lines']),
            'lots' => count($payload['lots']),
            'movements' => count($payload['movements']),
            'branch_id' => $branch['core_sucursal_id'],
            'item_stock' => round($itemStock, 3),
            'available_lot_stock' => round($availableStock, 3),
            'stock_difference' => round($itemStock - $availableStock, 3),
            'stock_mismatch_items' => $stockMismatchItems,
            'stock_absolute_difference' => round($stockAbsoluteDifference, 3),
            'movement_mismatch_items' => $movementMismatchItems,
            'movement_stock_difference' => round($movementStockDifference, 3),
            'negative_movement_balances' => $negativeMovementBalances,
        ];
    }

    /** @return array<string, mixed> */
    public function backup(Tenant $tenant): array
    {
        $backup = ['tenant_id' => $tenant->id, 'created_at' => now()->toIso8601String(), 'tables' => []];
        foreach (self::TABLES as $table) {
            $backup['tables'][$table] = DB::table($table)->where('tenant_id', $tenant->id)->get()->map(fn ($row) => (array) $row)->all();
        }

        return $backup;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, int|float>
     */
    public function import(Tenant $tenant, array $payload, bool $replace = false): array
    {
        $summary = $this->analyze($payload);
        if ($summary['stock_mismatch_items'] > 0 || $summary['movement_mismatch_items'] > 0 || $summary['negative_movement_balances'] > 0) {
            throw new InvalidArgumentException('La migración fue bloqueada porque lotes, kardex y existencias no terminan en el mismo saldo.');
        }

        return DB::transaction(function () use ($tenant, $payload, $replace, $summary): array {
            if ($replace) {
                $this->deleteTenantInventory($tenant);
            } elseif (DB::table('catalog_items')->where('tenant_id', $tenant->id)->exists()) {
                throw new InvalidArgumentException('El tenant ya contiene catálogo. Usa --replace para sustituirlo.');
            }

            $now = now();
            $branch = $this->branch($payload);
            $categoryMap = [];
            foreach ($payload['categories'] as $category) {
                $categoryMap[(int) $category['id']] = DB::table('catalog_categories')->insertGetId([
                    'tenant_id' => $tenant->id,
                    'name' => $category['nombre'],
                    'kind' => match ($category['tipo'] ?? null) {
                        'producto' => 'product', 'servicio' => 'service', default => 'mixed',
                    },
                    'status' => ($category['activo'] ?? true) ? 'active' : 'inactive',
                    'legacy_reference' => json_encode(['source' => 'stelfaro', 'id' => (int) $category['id']]),
                    'created_at' => $category['created_at'] ?? $now,
                    'updated_at' => $category['updated_at'] ?? $now,
                ]);
            }

            $supplierMap = [];
            foreach ($payload['suppliers'] as $supplier) {
                $supplierMap[(int) $supplier['id']] = DB::table('inventory_suppliers')->insertGetId([
                    'tenant_id' => $tenant->id,
                    'name' => $supplier['razon_social'],
                    'tax_id' => $this->blank($supplier['ruc'] ?? null),
                    'nrc' => $this->blank($supplier['nrc'] ?? null),
                    'phone' => $this->blank($supplier['telefono'] ?? null),
                    'email' => $this->blank($supplier['email'] ?? null),
                    'address' => $this->blank($supplier['direccion'] ?? null),
                    'status' => ($supplier['activo'] ?? true) ? 'active' : 'inactive',
                    'created_at' => $supplier['created_at'] ?? $now,
                    'updated_at' => $supplier['updated_at'] ?? $now,
                ]);
            }

            $lotsByItem = collect($payload['lots'])->groupBy('item_id');
            $itemMap = [];
            foreach ($payload['items'] as $item) {
                $available = $lotsByItem->get($item['id'], collect())->sum(fn (array $lot): float => (float) ($lot['cantidad_disponible'] ?? 0));
                $controlsInventory = (bool) ($item['controla_stock'] ?? false);
                $itemMap[(int) $item['id']] = DB::table('catalog_items')->insertGetId([
                    'tenant_id' => $tenant->id,
                    'catalog_category_id' => $categoryMap[(int) $item['categoria_item_id']] ?? null,
                    'legacy_item_id' => (int) $item['id'],
                    'sku' => $this->blank($item['codigo'] ?? null),
                    'name' => $item['nombre'],
                    'description' => $this->blank($item['descripcion'] ?? null),
                    'item_type' => ($item['tipo'] ?? null) === 'servicio' ? 'service' : 'product',
                    'unit_code' => $this->blank($item['unidad_medida'] ?? null) ?? '59',
                    'unit_name' => $this->blank($item['unidad_personalizada'] ?? null),
                    'units_per_package' => max((int) ($item['unidades_por_empaque'] ?? 1), 1),
                    'taxable' => (bool) ($item['afecto_igv'] ?? true),
                    'controls_inventory' => $controlsInventory,
                    'base_price' => (float) ($item['precio_venta'] ?? 0),
                    'base_price_includes_tax' => (bool) ($item['precio_venta_incluye_iva'] ?? false),
                    'reference_cost' => (float) ($item['precio_compra'] ?? 0),
                    'cost_source' => (float) ($item['precio_compra'] ?? 0) > 0 ? 'real' : 'none',
                    'stock_quantity' => $available,
                    'min_stock_quantity' => $controlsInventory ? max((float) ($item['stock_minimo'] ?? 1), 0) : 0,
                    'status' => ($item['activo'] ?? true) ? 'active' : 'inactive',
                    'metadata' => json_encode(['legacy_supplier_id' => $item['proveedor_id'] ?? null, 'legacy_stock' => (float) ($item['stock_actual'] ?? 0)]),
                    'created_at' => $item['created_at'] ?? $now,
                    'updated_at' => $item['updated_at'] ?? $now,
                ]);
            }

            $purchaseMap = [];
            foreach ($payload['purchases'] as $purchase) {
                $supplier = collect($payload['suppliers'])->firstWhere('id', $purchase['proveedor_id']);
                $purchaseMap[(int) $purchase['id']] = DB::table('inventory_purchases')->insertGetId([
                    'tenant_id' => $tenant->id,
                    'inventory_supplier_id' => $supplierMap[(int) $purchase['proveedor_id']] ?? null,
                    ...$branch,
                    'purchase_number' => (int) ($purchase['numero_compra'] ?? $purchase['id']),
                    'document_type' => $this->blank($purchase['tipo_documento'] ?? null),
                    'document_mode' => ($purchase['modalidad_documento'] ?? null) === 'fisico' ? 'physical' : ($purchase['modalidad_documento'] ?? 'manual'),
                    'document_number' => $this->blank($purchase['numero_documento'] ?? null),
                    'payment_condition' => $purchase['condicion_pago'] ?? 'cash',
                    'document_total' => (float) ($purchase['total'] ?? 0),
                    'is_consumable' => (bool) ($purchase['es_consumible'] ?? false),
                    'purchase_date' => $purchase['fecha'],
                    'subtotal' => (float) ($purchase['subtotal'] ?? 0),
                    'tax_amount' => (float) ($purchase['igv'] ?? 0),
                    'tax_perceived' => (float) ($purchase['iva_percibido'] ?? 0),
                    'fovial_per_unit' => (float) ($purchase['fovial_por_galon'] ?? 0),
                    'cotrans_per_unit' => (float) ($purchase['cotrans_por_galon'] ?? 0),
                    'other_non_taxable_total' => (float) ($purchase['total_otros_no_afectos'] ?? 0),
                    'total' => (float) ($purchase['total'] ?? 0),
                    'f07_operation_type' => $purchase['f07_tipo_operacion'] ?? null,
                    'f07_classification' => $purchase['f07_clasificacion'] ?? null,
                    'f07_sector' => $purchase['f07_sector'] ?? null,
                    'f07_cost_expense_type' => $purchase['f07_tipo_costo_gasto'] ?? null,
                    'supplier_snapshot' => json_encode($supplier ? (array) $supplier : []),
                    'import_metadata' => json_encode(['source' => 'stelfaro_legacy_inventory', 'legacy_tenant_id' => $payload['legacy_tenant_id'] ?? null, 'legacy_purchase_id' => (int) $purchase['id']]),
                    'status' => ($purchase['estado'] ?? null) === 'registrada' ? 'registered' : ($purchase['estado'] ?? 'registered'),
                    'created_by' => null,
                    'created_at' => $purchase['created_at'] ?? $now,
                    'updated_at' => $purchase['updated_at'] ?? $now,
                ]);
            }

            $lotsByLine = collect($payload['lots'])->groupBy('compra_detalle_id');
            $lineMap = [];
            foreach ($payload['purchase_lines'] as $line) {
                $item = collect($payload['items'])->firstWhere('id', $line['item_id']);
                $inventoryQuantity = $lotsByLine->get($line['id'], collect())->sum(fn (array $lot): float => (float) ($lot['cantidad_inicial'] ?? 0));
                $lineMap[(int) $line['id']] = DB::table('inventory_purchase_lines')->insertGetId([
                    'tenant_id' => $tenant->id,
                    'inventory_purchase_id' => $purchaseMap[(int) $line['compra_id']],
                    'catalog_item_id' => $itemMap[(int) $line['item_id']],
                    'description_snapshot' => $item['nombre'] ?? null,
                    'unit_code' => $item['unidad_medida'] ?? null,
                    'unit_name' => $item['unidad_personalizada'] ?? null,
                    'quantity' => (float) $line['cantidad'],
                    'input_unit_cost' => (float) ($line['costo_unitario'] ?? 0),
                    'unit_cost' => (float) ($line['costo_base_unitario'] ?? 0),
                    'base_unit_cost' => (float) ($line['costo_base_unitario'] ?? 0),
                    'tax_unit_amount' => (float) ($line['iva_unitario'] ?? 0),
                    'tax_rate' => (float) ($line['porcentaje_iva'] ?? 0),
                    'total_unit_amount' => (float) ($line['total_unitario'] ?? 0),
                    'tax_amount' => (float) ($line['igv_monto'] ?? 0),
                    'line_total' => (float) ($line['total_linea'] ?? 0),
                    'price_includes_tax' => false,
                    'no_inventory' => (bool) ($line['no_inventario'] ?? false),
                    'controls_inventory_snapshot' => (bool) ($item['controla_stock'] ?? false) && ! (bool) ($line['no_inventario'] ?? false),
                    'inventory_quantity' => $inventoryQuantity,
                    'created_at' => $line['created_at'] ?? $now,
                    'updated_at' => $line['updated_at'] ?? $now,
                ]);
            }

            foreach ($payload['lots'] as $lot) {
                $itemId = (int) $lot['item_id'];
                $available = (float) ($lot['cantidad_disponible'] ?? 0);
                DB::table('inventory_lots')->insert([
                    'tenant_id' => $tenant->id,
                    ...$branch,
                    'catalog_item_id' => $itemMap[$itemId],
                    'inventory_supplier_id' => isset($lot['proveedor_id']) ? ($supplierMap[(int) $lot['proveedor_id']] ?? null) : null,
                    'inventory_purchase_id' => isset($lot['compra_id']) ? ($purchaseMap[(int) $lot['compra_id']] ?? null) : null,
                    'inventory_purchase_line_id' => isset($lot['compra_detalle_id']) ? ($lineMap[(int) $lot['compra_detalle_id']] ?? null) : null,
                    'lot_code' => $lot['codigo_lote'],
                    'received_date' => $lot['fecha_compra'] ?? null,
                    'unit_cost' => (float) ($lot['costo_unitario'] ?? 0),
                    'initial_quantity' => (float) ($lot['cantidad_inicial'] ?? 0),
                    'available_quantity' => $available,
                    'status' => ($lot['activo'] ?? true) ? 'active' : 'inactive',
                    'created_at' => $lot['created_at'] ?? $now,
                    'updated_at' => $lot['updated_at'] ?? $now,
                ]);
            }

            $balanceByItem = [];
            foreach ($this->orderedMovements($payload['movements']) as $movement) {
                $itemId = (int) $movement['item_id'];
                $quantity = (float) $movement['cantidad'];
                $type = $movement['tipo_movimiento'] === 'entrada' ? 'entry' : 'exit';
                $legacyReferenceType = $this->blank($movement['referencia_tipo'] ?? null);
                $balanceByItem[$itemId] = ($balanceByItem[$itemId] ?? 0) + ($type === 'entry' ? $quantity : -$quantity);
                DB::table('inventory_movements')->insert([
                    'tenant_id' => $tenant->id,
                    ...$branch,
                    'catalog_item_id' => $itemMap[$itemId],
                    'inventory_lot_id' => null,
                    'movement_type' => $type,
                    'reason' => $this->movementReason($movement['motivo'] ?? null),
                    'quantity' => $quantity,
                    'unit_cost' => isset($movement['costo_unitario']) ? (float) $movement['costo_unitario'] : null,
                    'balance_after' => $balanceByItem[$itemId],
                    'reference_type' => $legacyReferenceType ? 'legacy_'.$legacyReferenceType : 'legacy_kardex',
                    'reference_id' => isset($movement['referencia_id']) ? (string) $movement['referencia_id'] : (string) $movement['id'],
                    'reference_number' => null,
                    'notes' => 'Movimiento histórico importado desde Stelfaro legado.',
                    'created_by' => null,
                    'created_at' => $movement['fecha'] ?? $movement['created_at'] ?? $now,
                    'updated_at' => $movement['updated_at'] ?? $movement['fecha'] ?? $now,
                ]);
            }

            return $summary;
        });
    }

    /** @return array<string, int> */
    public function clear(Tenant $tenant): array
    {
        return DB::transaction(function () use ($tenant): array {
            $counts = [];
            foreach (self::TABLES as $table) {
                $counts[$table] = DB::table($table)->where('tenant_id', $tenant->id)->count();
            }
            $this->deleteTenantInventory($tenant);

            return $counts;
        });
    }

    private function deleteTenantInventory(Tenant $tenant): void
    {
        foreach ([
            'inventory_sale_allocations', 'inventory_movements', 'inventory_reservation_lines', 'inventory_reservations',
            'inventory_sale_lines', 'inventory_sales', 'inventory_physical_count_lines', 'inventory_physical_counts',
            'inventory_transfer_lines', 'inventory_transfers', 'inventory_lots', 'inventory_purchase_lines',
            'inventory_purchases', 'catalog_items', 'catalog_categories', 'inventory_suppliers',
        ] as $table) {
            DB::table($table)->where('tenant_id', $tenant->id)->delete();
        }
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function ids(array $rows, string $key, string $label): array
    {
        $ids = array_map(fn (array $row): int => (int) ($row[$key] ?? 0), $rows);
        if (in_array(0, $ids, true) || count($ids) !== count(array_unique($ids))) {
            throw new InvalidArgumentException("Hay identificadores inválidos o repetidos en {$label}.");
        }

        return array_fill_keys($ids, true);
    }

    private function references(mixed $id, array $ids, string $label, bool $nullable = true): void
    {
        if (($id === null || $id === '') && $nullable) {
            return;
        }
        if (! isset($ids[(int) $id])) {
            throw new InvalidArgumentException("No se encontró la referencia {$label}: {$id}.");
        }
    }

    private function blank(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    /** @return array{core_sucursal_id:int,core_sucursal_code:string,core_sucursal_name:string} */
    private function branch(array $payload): array
    {
        $branch = is_array($payload['target_branch'] ?? null) ? $payload['target_branch'] : [];
        $id = (int) ($branch['id'] ?? 0);
        $code = trim((string) ($branch['code'] ?? ''));
        $name = trim((string) ($branch['name'] ?? ''));
        if ($id < 1 || $code === '' || $name === '') {
            throw new InvalidArgumentException('Debes indicar la Casa Matriz de destino completa.');
        }

        return ['core_sucursal_id' => $id, 'core_sucursal_code' => $code, 'core_sucursal_name' => $name];
    }

    /** @param array<int, array<string, mixed>> $movements */
    private function orderedMovements(array $movements): array
    {
        usort($movements, fn (array $a, array $b): int => [($a['fecha'] ?? $a['created_at'] ?? ''), (int) $a['id']] <=> [($b['fecha'] ?? $b['created_at'] ?? ''), (int) $b['id']]);

        return $movements;
    }

    private function movementReason(mixed $reason): string
    {
        return match ($reason) {
            'compra' => 'purchase',
            'venta' => 'sale',
            'anulacion' => 'reversal',
            'ajuste_manual' => 'manual_adjustment',
            default => 'legacy_import',
        };
    }
}

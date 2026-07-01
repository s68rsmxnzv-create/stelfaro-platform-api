<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryPurchase;
use App\Models\InventorySaleLine;
use App\Models\Tenant;
use App\Services\PlatformAccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryReportController extends Controller
{
    public function sales(Request $request, Tenant $tenant, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canViewTenantCatalog($request->user(), $tenant), 403);

        $from = $request->query('from');
        $to = $request->query('to');
        $branchId = $request->filled('core_sucursal_id') ? (int) $request->query('core_sucursal_id') : null;

        $rows = InventorySaleLine::query()
            ->where('inventory_sale_lines.tenant_id', $tenant->id)
            ->join('inventory_sales', 'inventory_sales.id', '=', 'inventory_sale_lines.inventory_sale_id')
            ->leftJoin('catalog_items', 'catalog_items.id', '=', 'inventory_sale_lines.catalog_item_id')
            ->where('inventory_sales.status', 'active')
            ->when($from, fn ($query) => $query->whereDate('inventory_sales.sale_date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('inventory_sales.sale_date', '<=', $to))
            ->when($branchId, fn ($query) => $query->where('inventory_sales.core_sucursal_id', $branchId))
            ->groupBy('inventory_sale_lines.catalog_item_id', 'inventory_sale_lines.line_origin', 'inventory_sale_lines.description_snapshot', 'catalog_items.sku', 'catalog_items.name')
            ->select([
                'inventory_sale_lines.catalog_item_id',
                'inventory_sale_lines.line_origin',
                DB::raw("COALESCE(catalog_items.sku, 'LIBRE') as sku"),
                DB::raw("COALESCE(catalog_items.name, inventory_sale_lines.description_snapshot, 'Descripción libre') as name"),
                DB::raw('SUM(inventory_sale_lines.quantity) as quantity'),
                DB::raw('SUM(inventory_sale_lines.net_total) as sales_total'),
                DB::raw('SUM(inventory_sale_lines.quantity * inventory_sale_lines.reference_unit_cost) as reference_cost_total'),
            ])
            ->orderByDesc('sales_total')
            ->limit(200)
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function kardex(Request $request, Tenant $tenant, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canViewTenantCatalog($request->user(), $tenant), 403);

        $query = InventoryMovement::query()
            ->where('tenant_id', $tenant->id)
            ->with('catalogItem:id,sku,name', 'lot:id,lot_code')
            ->when($request->filled('catalog_item_id'), fn ($q) => $q->where('catalog_item_id', (int) $request->query('catalog_item_id')))
            ->when($request->filled('core_sucursal_id'), fn ($q) => $q->where('core_sucursal_id', (int) $request->query('core_sucursal_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->query('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->query('to')))
            ->orderByDesc('id');

        return response()->json($query->paginate((int) min(max((int) $request->query('per_page', 50), 1), 100)));
    }

    public function margin(Request $request, Tenant $tenant, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canViewTenantCatalog($request->user(), $tenant), 403);

        $sales = $this->sales($request, $tenant, $policy)->getData(true)['data'] ?? [];
        $rows = collect($sales)->map(function (array $row): array {
            $salesTotal = (float) ($row['sales_total'] ?? 0);
            $costTotal = (float) ($row['reference_cost_total'] ?? 0);
            $row['margin_total'] = round($salesTotal - $costTotal, 2);
            $row['margin_percent'] = $salesTotal > 0 ? round((($salesTotal - $costTotal) / $salesTotal) * 100, 2) : 0;

            return $row;
        })->values();

        return response()->json(['data' => $rows]);
    }

    public function stockAlerts(Request $request, Tenant $tenant, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canViewTenantCatalog($request->user(), $tenant), 403);

        $branchId = $request->filled('core_sucursal_id') ? (int) $request->query('core_sucursal_id') : null;
        $items = CatalogItem::query()
            ->where('tenant_id', $tenant->id)
            ->where('controls_inventory', true)
            ->where('min_stock_quantity', '>', 0)
            ->orderBy('name')
            ->get()
            ->map(function (CatalogItem $item) use ($tenant, $branchId): array {
                $stock = round((float) InventoryLot::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('catalog_item_id', $item->id)
                    ->when($branchId, fn ($query) => $query->where('core_sucursal_id', $branchId))
                    ->where('available_quantity', '>', 0)
                    ->sum('available_quantity'), 3);

                return [
                    'id' => $item->id,
                    'sku' => $item->sku,
                    'name' => $item->name,
                    'stock_quantity' => $stock,
                    'min_stock_quantity' => (float) $item->min_stock_quantity,
                    'below_minimum' => $stock <= (float) $item->min_stock_quantity,
                ];
            })
            ->filter(fn (array $row): bool => (bool) $row['below_minimum'])
            ->values();

        return response()->json(['data' => $items]);
    }

    public function purchaseAnnex(Request $request, Tenant $tenant, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canViewTenantCatalog($request->user(), $tenant), 403);

        $from = $request->query('from');
        $to = $request->query('to');

        $rows = InventoryPurchase::query()
            ->where('tenant_id', $tenant->id)
            ->with('supplier:id,name,tax_id,nrc')
            ->when($from, fn ($query) => $query->whereDate('purchase_date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('purchase_date', '<=', $to))
            ->orderBy('purchase_date')
            ->orderBy('id')
            ->get()
            ->map(function (InventoryPurchase $purchase): array {
                $supplierSnapshot = is_array($purchase->supplier_snapshot) ? $purchase->supplier_snapshot : [];
                $supplier = $purchase->supplier;

                return [
                    'purchase_id' => $purchase->id,
                    'purchase_date' => $purchase->purchase_date?->toDateString(),
                    'document_type' => $purchase->document_type,
                    'document_mode' => $purchase->document_mode,
                    'document_number' => $purchase->document_number,
                    'supplier_name' => $supplier?->name ?? ($supplierSnapshot['name'] ?? null),
                    'supplier_tax_id' => $supplier?->tax_id ?? ($supplierSnapshot['tax_id'] ?? null),
                    'supplier_nrc' => $supplier?->nrc ?? ($supplierSnapshot['nrc'] ?? null),
                    'payment_condition' => $purchase->payment_condition,
                    'subtotal' => $purchase->subtotal,
                    'tax_amount' => $purchase->tax_amount,
                    'tax_perceived' => $purchase->tax_perceived,
                    'other_non_taxable_total' => $purchase->other_non_taxable_total,
                    'total' => $purchase->total,
                    'f07_operation_type' => $purchase->f07_operation_type,
                    'f07_classification' => $purchase->f07_classification,
                    'f07_sector' => $purchase->f07_sector,
                    'f07_cost_expense_type' => $purchase->f07_cost_expense_type,
                    'import_metadata' => $purchase->import_metadata,
                ];
            })
            ->values();

        return response()->json(['data' => $rows]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryPurchase;
use App\Models\InventorySaleLine;
use App\Models\Tenant;
use App\Services\Pdf\BrowsershotPdfRenderer;
use App\Services\PlatformAccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class InventoryReportController extends Controller
{
    private const REPORT_LIMIT = 500;

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

    public function pdf(Request $request, Tenant $tenant, string $report, PlatformAccessPolicy $policy, BrowsershotPdfRenderer $pdfRenderer): Response
    {
        abort_unless($policy->canViewTenantCatalog($request->user(), $tenant), 403);

        $definition = $this->reportDefinition($request, $tenant, $report);
        abort_if($definition === null, 404);

        $html = view('inventory.reports.pdf', [
            'tenant' => $tenant,
            'title' => $definition['title'],
            'columns' => $definition['columns'],
            'rows' => $definition['rows'],
            'period' => $this->periodLabel($request),
            'scope' => $this->branchLabel($request, $definition['rows']),
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        $disposition = $request->query('download') ? 'attachment' : 'inline';
        $filename = $this->reportFilename($tenant, $definition['name']);

        return response($pdfRenderer->render($html), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$filename.'.pdf"',
        ]);
    }

    public function countSheetPdf(Request $request, Tenant $tenant, PlatformAccessPolicy $policy, BrowsershotPdfRenderer $pdfRenderer): Response
    {
        abort_unless($policy->canViewTenantCatalog($request->user(), $tenant), 403);

        $rows = $this->countSheetRows($request, $tenant)->all();
        $html = view('inventory.reports.pdf', [
            'tenant' => $tenant,
            'title' => 'Hoja de conteo físico',
            'columns' => [
                ['key' => 'number', 'label' => '#', 'align' => 'right'],
                ['key' => 'sku', 'label' => 'Código'],
                ['key' => 'name', 'label' => 'Producto'],
                ['key' => 'unit', 'label' => 'Unidad'],
                ['key' => 'system_quantity', 'label' => 'Sistema', 'align' => 'right'],
                ['key' => 'counted_quantity', 'label' => 'Conteo físico', 'writeable' => true],
                ['key' => 'difference', 'label' => 'Diferencia', 'writeable' => true],
                ['key' => 'notes', 'label' => 'Notas', 'writeable' => true],
            ],
            'rows' => $rows,
            'period' => 'Conteo: '.$request->query('count_date', now()->toDateString()),
            'scope' => $this->branchLabel($request, $rows),
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        $disposition = $request->query('download') ? 'attachment' : 'inline';
        $filename = $this->reportFilename($tenant, 'hoja-conteo-fisico');

        return response($pdfRenderer->render($html, ['landscape' => true]), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$filename.'.pdf"',
        ]);
    }

    private function reportDefinition(Request $request, Tenant $tenant, string $report): ?array
    {
        return match ($report) {
            'ventas' => [
                'name' => 'ventas-producto',
                'title' => 'Ventas por producto',
                'columns' => [
                    ['key' => 'sku', 'label' => 'Código'],
                    ['key' => 'name', 'label' => 'Producto'],
                    ['key' => 'line_origin', 'label' => 'Origen'],
                    ['key' => 'quantity', 'label' => 'Cantidad', 'align' => 'right'],
                    ['key' => 'sales_total', 'label' => 'Venta', 'align' => 'right', 'money' => true],
                    ['key' => 'reference_cost_total', 'label' => 'Costo ref.', 'align' => 'right', 'money' => true],
                ],
                'rows' => $this->salesRows($request, $tenant)->all(),
            ],
            'margen' => [
                'name' => 'margen-producto',
                'title' => 'Margen referencial',
                'columns' => [
                    ['key' => 'sku', 'label' => 'Código'],
                    ['key' => 'name', 'label' => 'Producto'],
                    ['key' => 'quantity', 'label' => 'Cantidad', 'align' => 'right'],
                    ['key' => 'sales_total', 'label' => 'Venta', 'align' => 'right', 'money' => true],
                    ['key' => 'reference_cost_total', 'label' => 'Costo ref.', 'align' => 'right', 'money' => true],
                    ['key' => 'margin_total', 'label' => 'Margen', 'align' => 'right', 'money' => true],
                    ['key' => 'margin_percent', 'label' => '%', 'align' => 'right', 'percent' => true],
                ],
                'rows' => $this->marginRows($request, $tenant)->all(),
            ],
            'kardex' => [
                'name' => 'kardex',
                'title' => 'Kardex',
                'columns' => [
                    ['key' => 'created_at', 'label' => 'Fecha'],
                    ['key' => 'producto', 'label' => 'Producto'],
                    ['key' => 'sku', 'label' => 'Código'],
                    ['key' => 'lote', 'label' => 'Lote'],
                    ['key' => 'tipo', 'label' => 'Tipo'],
                    ['key' => 'reason', 'label' => 'Motivo'],
                    ['key' => 'sucursal', 'label' => 'Sucursal'],
                    ['key' => 'quantity', 'label' => 'Cantidad', 'align' => 'right'],
                    ['key' => 'unit_cost', 'label' => 'Costo', 'align' => 'right', 'money' => true],
                    ['key' => 'balance_after', 'label' => 'Saldo', 'align' => 'right'],
                ],
                'rows' => $this->kardexRows($request, $tenant)->all(),
            ],
            'existencias' => [
                'name' => 'existencias',
                'title' => 'Existencias',
                'columns' => [
                    ['key' => 'sku', 'label' => 'Código'],
                    ['key' => 'name', 'label' => 'Producto'],
                    ['key' => 'unit_name', 'label' => 'Unidad'],
                    ['key' => 'branch_stock_quantity', 'label' => 'Stock', 'align' => 'right'],
                    ['key' => 'reference_cost', 'label' => 'Costo ref.', 'align' => 'right', 'money' => true],
                    ['key' => 'valor', 'label' => 'Valor ref.', 'align' => 'right', 'money' => true],
                ],
                'rows' => $this->stockRows($request, $tenant)->all(),
            ],
            'lotes' => [
                'name' => 'lotes',
                'title' => 'Lotes',
                'columns' => [
                    ['key' => 'lot_code', 'label' => 'Lote'],
                    ['key' => 'producto', 'label' => 'Producto'],
                    ['key' => 'sku', 'label' => 'Código'],
                    ['key' => 'supplier_name', 'label' => 'Proveedor'],
                    ['key' => 'received_date', 'label' => 'Fecha'],
                    ['key' => 'sucursal', 'label' => 'Sucursal'],
                    ['key' => 'initial_quantity', 'label' => 'Inicial', 'align' => 'right'],
                    ['key' => 'available_quantity', 'label' => 'Disponible', 'align' => 'right'],
                    ['key' => 'unit_cost', 'label' => 'Costo', 'align' => 'right', 'money' => true],
                    ['key' => 'available_value', 'label' => 'Valor', 'align' => 'right', 'money' => true],
                    ['key' => 'status', 'label' => 'Estado'],
                ],
                'rows' => $this->lotRows($request, $tenant)->all(),
            ],
            'alertas' => [
                'name' => 'alertas-stock',
                'title' => 'Alertas de stock mínimo',
                'columns' => [
                    ['key' => 'sku', 'label' => 'Código'],
                    ['key' => 'name', 'label' => 'Producto'],
                    ['key' => 'stock_quantity', 'label' => 'Stock', 'align' => 'right'],
                    ['key' => 'min_stock_quantity', 'label' => 'Mínimo', 'align' => 'right'],
                ],
                'rows' => $this->stockAlertRows($request, $tenant)->all(),
            ],
            default => null,
        };
    }

    private function salesRows(Request $request, Tenant $tenant)
    {
        $from = $request->query('from');
        $to = $request->query('to');
        $branchId = $request->filled('core_sucursal_id') ? (int) $request->query('core_sucursal_id') : null;

        return InventorySaleLine::query()
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
            ->limit(self::REPORT_LIMIT)
            ->get();
    }

    private function marginRows(Request $request, Tenant $tenant)
    {
        return $this->salesRows($request, $tenant)->map(function ($row): array {
            $salesTotal = (float) ($row->sales_total ?? 0);
            $costTotal = (float) ($row->reference_cost_total ?? 0);

            return [
                ...$row->toArray(),
                'margin_total' => round($salesTotal - $costTotal, 2),
                'margin_percent' => $salesTotal > 0 ? round((($salesTotal - $costTotal) / $salesTotal) * 100, 2) : 0,
            ];
        })->values();
    }

    private function kardexRows(Request $request, Tenant $tenant)
    {
        return InventoryMovement::query()
            ->where('tenant_id', $tenant->id)
            ->with('catalogItem:id,sku,name', 'lot:id,lot_code')
            ->when($request->filled('catalog_item_id'), fn ($q) => $q->where('catalog_item_id', (int) $request->query('catalog_item_id')))
            ->when($request->filled('core_sucursal_id'), fn ($q) => $q->where('core_sucursal_id', (int) $request->query('core_sucursal_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->query('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->query('to')))
            ->orderByDesc('id')
            ->limit(self::REPORT_LIMIT)
            ->get()
            ->map(fn (InventoryMovement $movement): array => [
                'created_at' => $movement->created_at?->format('Y-m-d H:i'),
                'producto' => $movement->catalogItem?->name ?? 'Producto',
                'sku' => $movement->catalogItem?->sku ?? '',
                'lote' => $movement->lot?->lot_code ?? '',
                'tipo' => $movement->movement_type === 'entry' ? 'Entrada' : 'Salida',
                'reason' => $movement->reason,
                'sucursal' => $movement->core_sucursal_code ?: $movement->core_sucursal_name,
                'quantity' => (float) $movement->quantity,
                'unit_cost' => $movement->unit_cost === null ? null : (float) $movement->unit_cost,
                'balance_after' => (float) $movement->balance_after,
            ]);
    }

    private function stockRows(Request $request, Tenant $tenant)
    {
        $branchId = $request->filled('core_sucursal_id') ? (int) $request->query('core_sucursal_id') : null;

        return CatalogItem::query()
            ->where('tenant_id', $tenant->id)
            ->where('controls_inventory', true)
            ->orderBy('name')
            ->limit(self::REPORT_LIMIT)
            ->get()
            ->map(function (CatalogItem $item) use ($tenant, $branchId): array {
                $stock = round((float) InventoryLot::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('catalog_item_id', $item->id)
                    ->when($branchId, fn ($query) => $query->where('core_sucursal_id', $branchId))
                    ->where('available_quantity', '>', 0)
                    ->sum('available_quantity'), 3);
                $cost = (float) ($item->reference_cost ?? 0);

                return [
                    'sku' => $item->sku,
                    'name' => $item->name,
                    'unit_name' => $item->unit_name ?: $item->unit_code,
                    'branch_stock_quantity' => $stock,
                    'reference_cost' => $cost,
                    'valor' => round($stock * $cost, 2),
                ];
            });
    }

    private function countSheetRows(Request $request, Tenant $tenant)
    {
        $branchId = $request->filled('core_sucursal_id') ? (int) $request->query('core_sucursal_id') : null;

        return CatalogItem::query()
            ->where('tenant_id', $tenant->id)
            ->where('controls_inventory', true)
            ->orderBy('name')
            ->limit(1000)
            ->get()
            ->values()
            ->map(function (CatalogItem $item, int $index) use ($tenant, $branchId): array {
                $stock = round((float) InventoryLot::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('catalog_item_id', $item->id)
                    ->when($branchId, fn ($query) => $query->where('core_sucursal_id', $branchId))
                    ->where('available_quantity', '>', 0)
                    ->sum('available_quantity'), 3);

                return [
                    'number' => $index + 1,
                    'sku' => $item->sku,
                    'name' => $item->name,
                    'unit' => $item->unit_name ?: $item->unit_code,
                    'system_quantity' => $stock,
                    'counted_quantity' => '',
                    'difference' => '',
                    'notes' => '',
                ];
            });
    }

    private function lotRows(Request $request, Tenant $tenant)
    {
        return InventoryLot::query()
            ->where('tenant_id', $tenant->id)
            ->with('catalogItem:id,sku,name', 'supplier:id,name')
            ->when($request->filled('core_sucursal_id'), fn ($q) => $q->where('core_sucursal_id', (int) $request->query('core_sucursal_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('received_date', '>=', $request->query('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('received_date', '<=', $request->query('to')))
            ->orderByDesc('received_date')
            ->orderByDesc('id')
            ->limit(self::REPORT_LIMIT)
            ->get()
            ->map(fn (InventoryLot $lot): array => [
                'lot_code' => $lot->lot_code,
                'producto' => $lot->catalogItem?->name ?? 'Producto',
                'sku' => $lot->catalogItem?->sku ?? '',
                'supplier_name' => $lot->supplier?->name ?? '',
                'received_date' => $lot->received_date?->toDateString(),
                'sucursal' => $lot->core_sucursal_code ?: $lot->core_sucursal_name,
                'initial_quantity' => (float) $lot->initial_quantity,
                'available_quantity' => (float) $lot->available_quantity,
                'unit_cost' => (float) $lot->unit_cost,
                'available_value' => round((float) $lot->available_quantity * (float) $lot->unit_cost, 2),
                'status' => $lot->status,
            ]);
    }

    private function stockAlertRows(Request $request, Tenant $tenant)
    {
        $branchId = $request->filled('core_sucursal_id') ? (int) $request->query('core_sucursal_id') : null;

        return CatalogItem::query()
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
                    'sku' => $item->sku,
                    'name' => $item->name,
                    'stock_quantity' => $stock,
                    'min_stock_quantity' => (float) $item->min_stock_quantity,
                    'below_minimum' => $stock <= (float) $item->min_stock_quantity,
                ];
            })
            ->filter(fn (array $row): bool => (bool) $row['below_minimum'])
            ->take(self::REPORT_LIMIT)
            ->values();
    }

    private function periodLabel(Request $request): string
    {
        $from = $request->query('from', 'inicio');
        $to = $request->query('to', now()->toDateString());

        return $from.' al '.$to;
    }

    private function branchLabel(Request $request, array $rows): string
    {
        if (! $request->filled('core_sucursal_id')) {
            return 'Todas las sucursales';
        }

        foreach ($rows as $row) {
            $sucursal = is_array($row) ? ($row['sucursal'] ?? null) : ($row->sucursal ?? null);
            if ($sucursal) {
                return (string) $sucursal;
            }
        }

        return 'Sucursal '.$request->query('core_sucursal_id');
    }

    private function reportFilename(Tenant $tenant, string $report): string
    {
        $company = str($tenant->name ?: 'empresa')
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '-')
            ->trim('-')
            ->limit(40, '')
            ->toString() ?: 'empresa';

        return $company.'-'.$report.'-'.now()->format('Ymd-His');
    }

}

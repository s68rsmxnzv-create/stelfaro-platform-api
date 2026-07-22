<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\CashExpense;
use App\Models\InventorySale;
use App\Models\Tenant;
use App\Services\PlatformAccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CommercialSalesReportController extends Controller
{
    public function __invoke(Request $request, Tenant $tenant, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canViewTenantCatalog($request->user(), $tenant), 403);
        $data = $request->validate([
            'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date'],
            'source_type' => ['nullable', Rule::in(['dte', 'workshop_order', 'sales_order'])],
            'document_type' => ['nullable', Rule::in(['01', '03', '05', '06', '14'])],
            'payment_status' => ['nullable', Rule::in(['paid', 'receivable'])],
            'core_sucursal_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        if (($data['date_from'] ?? null) && ($data['date_to'] ?? null) && $data['date_to'] < $data['date_from']) {
            throw ValidationException::withMessages(['date_to' => 'La fecha final debe ser igual o posterior a la inicial.']);
        }

        $query = InventorySale::query()->where('inventory_sales.tenant_id', $tenant->id)->where('inventory_sales.status', 'active');
        $query->when($data['date_from'] ?? null, fn ($q, $date) => $q->whereDate('sale_date', '>=', $date));
        $query->when($data['date_to'] ?? null, fn ($q, $date) => $q->whereDate('sale_date', '<=', $date));
        $query->when($data['source_type'] ?? null, fn ($q, $source) => $q->where('source_type', $source));
        $query->when($data['document_type'] ?? null, fn ($q, $type) => $q->where('fiscal_document_type', $type));
        $query->when($data['payment_status'] ?? null, fn ($q, $status) => $q->where('metadata->payment_status', $status));
        $query->when($data['core_sucursal_id'] ?? null, fn ($q, $branchId) => $q->where('core_sucursal_id', $branchId));

        $summary = (clone $query)->selectRaw('COUNT(*) as transactions, COALESCE(SUM(net_amount * reporting_sign), 0) as net, COALESCE(SUM(tax_amount * reporting_sign), 0) as tax, COALESCE(SUM(total_amount * reporting_sign), 0) as total')->first();
        $receivable = (clone $query)->get(['total_amount', 'reporting_sign', 'metadata'])
            ->filter(fn (InventorySale $sale): bool => data_get($sale->metadata, 'payment_status') === 'receivable')
            ->sum(fn (InventorySale $sale): float => (float) data_get($sale->metadata, 'outstanding_amount', $sale->total_amount) * (int) $sale->reporting_sign);
        $cost = (float) DB::query()->fromSub(
            (clone $query)->join('inventory_sale_lines', 'inventory_sale_lines.inventory_sale_id', '=', 'inventory_sales.id')
                ->selectRaw('inventory_sale_lines.quantity * inventory_sale_lines.reference_unit_cost * inventory_sales.reporting_sign as cost'),
            'sale_costs'
        )->sum('cost');
        $workshopSales = (clone $query)
            ->where('inventory_sales.source_type', 'workshop_order')
            ->selectRaw('CAST(inventory_sales.source_id AS BIGINT)');
        $directWorkshopCost = (float) CashExpense::query()
            ->where('tenant_id', $tenant->id)
            ->where('destination', 'direct_order')
            ->whereNotNull('workshop_order_id')
            ->where('status', '!=', 'reversed')
            ->whereIn('workshop_order_id', $workshopSales)
            ->sum('amount');
        $cost += $directWorkshopCost;
        $rows = $query->latest('sale_date')->latest('id')->paginate((int) ($data['per_page'] ?? 20));

        return response()->json([
            'summary' => ['transactions' => (int) $summary->transactions, 'net' => round((float) $summary->net, 2), 'tax' => round((float) $summary->tax, 2), 'total' => round((float) $summary->total, 2), 'receivable' => round((float) $receivable, 2), 'cost' => round($cost, 2), 'margin' => round((float) $summary->net - $cost, 2)],
            'data' => collect($rows->items())->map(fn (InventorySale $sale) => ['id' => $sale->id, 'date' => $sale->sale_date?->format('Y-m-d'), 'source_type' => $sale->source_type, 'source_id' => $sale->source_id, 'source_number' => $sale->source_number, 'document_type' => $sale->fiscal_document_type, 'operation_kind' => $sale->operation_kind, 'customer_name' => data_get($sale->metadata, 'customer_name'), 'payment_status' => data_get($sale->metadata, 'payment_status', 'paid'), 'outstanding_amount' => data_get($sale->metadata, 'payment_status') === 'receivable' ? (float) data_get($sale->metadata, 'outstanding_amount', $sale->total_amount) : 0.0, 'net' => (float) $sale->net_amount * $sale->reporting_sign, 'tax' => (float) $sale->tax_amount * $sale->reporting_sign, 'total' => (float) $sale->total_amount * $sale->reporting_sign])->values(),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()],
        ]);
    }
}

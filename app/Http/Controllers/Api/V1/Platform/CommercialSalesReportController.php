<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\CashExpense;
use App\Models\CashMovement;
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
            'source_type' => ['nullable', Rule::in(['dte', 'workshop_order'])],
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
        $payments = $this->paymentBreakdown($tenant, $query);
        $rows = $query->latest('sale_date')->latest('id')->paginate((int) ($data['per_page'] ?? 20));
        $rowPayments = $this->rowPaymentBreakdown($tenant, collect($rows->items()));
        $unclassified = max(0, round((float) $summary->total - (float) $receivable - $payments['total'], 2));

        return response()->json([
            'summary' => ['transactions' => (int) $summary->transactions, 'net' => round((float) $summary->net, 2), 'tax' => round((float) $summary->tax, 2), 'total' => round((float) $summary->total, 2), 'receivable' => round((float) $receivable, 2), 'cost' => round($cost, 2), 'margin' => round((float) $summary->net - $cost, 2), 'payments' => [...$payments['methods'], 'recorded' => $payments['total'], 'unclassified' => $unclassified]],
            'data' => collect($rows->items())->map(function (InventorySale $sale) use ($rowPayments): array {
                $paymentMethods = $rowPayments[$sale->source_type.':'.$sale->source_id] ?? $this->emptyPaymentMethods();

                return ['id' => $sale->id, 'date' => $sale->sale_date?->format('Y-m-d'), 'source_type' => $sale->source_type, 'source_id' => $sale->source_id, 'source_number' => $sale->source_number, 'document_type' => $sale->fiscal_document_type, 'operation_kind' => $sale->operation_kind, 'customer_name' => data_get($sale->metadata, 'customer_name'), 'payment_status' => data_get($sale->metadata, 'payment_status', 'paid'), 'outstanding_amount' => data_get($sale->metadata, 'payment_status') === 'receivable' ? (float) data_get($sale->metadata, 'outstanding_amount', $sale->total_amount) : 0.0, 'payment_methods' => $paymentMethods, 'collected' => round(array_sum($paymentMethods), 2), 'net' => (float) $sale->net_amount * $sale->reporting_sign, 'tax' => (float) $sale->tax_amount * $sale->reporting_sign, 'total' => (float) $sale->total_amount * $sale->reporting_sign];
            })->values(),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()],
        ]);
    }

    /** @return array{methods:array{cash:float,card:float,transfer:float,other:float},total:float} */
    private function paymentBreakdown(Tenant $tenant, $salesQuery): array
    {
        $dteSales = (clone $salesQuery)->where('inventory_sales.source_type', 'dte')->select('inventory_sales.source_id');
        $workshopSales = (clone $salesQuery)->where('inventory_sales.source_type', 'workshop_order')->selectRaw('CAST(inventory_sales.source_id AS BIGINT)');
        $rows = CashMovement::query()
            ->where('cash_movements.tenant_id', $tenant->id)
            ->whereNull('reversed_at')
            ->where(function ($query) use ($dteSales, $workshopSales): void {
                $query->where(fn ($dte) => $dte->where('source_type', 'dte')->whereIn('source_id', $dteSales))
                    ->orWhereIn('workshop_order_id', $workshopSales);
            })
            ->selectRaw("method, COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0) as amount")
            ->groupBy('method')
            ->pluck('amount', 'method');
        $methods = $this->emptyPaymentMethods();
        foreach ($rows as $method => $amount) {
            $key = array_key_exists($method, $methods) ? $method : 'other';
            $methods[$key] = round($methods[$key] + (float) $amount, 2);
        }

        return ['methods' => $methods, 'total' => round(array_sum($methods), 2)];
    }

    /** @return array<string,array{cash:float,card:float,transfer:float,other:float}> */
    private function rowPaymentBreakdown(Tenant $tenant, $sales): array
    {
        $dteIds = $sales->where('source_type', 'dte')->pluck('source_id')->all();
        $workshopIds = $sales->where('source_type', 'workshop_order')->pluck('source_id')->map(fn ($id) => (int) $id)->all();
        if ($dteIds === [] && $workshopIds === []) {
            return [];
        }
        $movements = CashMovement::query()
            ->where('tenant_id', $tenant->id)
            ->whereNull('reversed_at')
            ->where(function ($query) use ($dteIds, $workshopIds): void {
                $query->when($dteIds !== [], fn ($nested) => $nested->where(fn ($dte) => $dte->where('source_type', 'dte')->whereIn('source_id', $dteIds)))
                    ->when($workshopIds !== [], fn ($nested) => $nested->orWhereIn('workshop_order_id', $workshopIds));
            })
            ->get(['source_type', 'source_id', 'workshop_order_id', 'direction', 'method', 'amount']);
        $result = [];
        foreach ($movements as $movement) {
            $key = $movement->workshop_order_id ? 'workshop_order:'.$movement->workshop_order_id : 'dte:'.$movement->source_id;
            $result[$key] ??= $this->emptyPaymentMethods();
            $method = array_key_exists($movement->method, $result[$key]) ? $movement->method : 'other';
            $sign = $movement->direction === 'in' ? 1 : -1;
            $result[$key][$method] = round($result[$key][$method] + ((float) $movement->amount * $sign), 2);
        }

        return $result;
    }

    /** @return array{cash:float,card:float,transfer:float,other:float} */
    private function emptyPaymentMethods(): array
    {
        return ['cash' => 0.0, 'card' => 0.0, 'transfer' => 0.0, 'other' => 0.0];
    }
}

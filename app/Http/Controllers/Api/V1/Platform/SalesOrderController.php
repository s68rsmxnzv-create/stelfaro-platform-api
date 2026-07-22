<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use App\Models\SalesOrder;
use App\Models\SalesOrderPayment;
use App\Models\Tenant;
use App\Services\Cash\CashService;
use App\Services\Inventory\InventoryService;
use App\Services\PlatformAccessPolicy;
use App\Services\PlatformAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class SalesOrderController extends Controller
{
    public function index(Request $request, Tenant $tenant, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canViewTenantCatalog($request->user(), $tenant), 403);
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:160'],
            'status' => ['nullable', Rule::in(['open', 'delivered', 'cancelled'])],
            'payment_status' => ['nullable', Rule::in(['paid', 'receivable'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        $query = SalesOrder::query()->where('tenant_id', $tenant->id)->with(['lines.catalogItem', 'payments']);
        $query->when($data['q'] ?? null, function ($query, string $search): void {
            $term = '%'.mb_strtolower(trim($search)).'%';
            $query->where(fn ($nested) => $nested
                ->whereRaw('LOWER(customer_name) LIKE ?', [$term])
                ->orWhereRaw('LOWER(COALESCE(customer_phone, \'\')) LIKE ?', [$term])
                ->orWhereRaw('CAST(order_number AS TEXT) LIKE ?', [$term])
                ->orWhereHas('lines', fn ($lines) => $lines->whereRaw('LOWER(description_snapshot) LIKE ?', [$term])));
        });
        $query->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status));
        $query->when($data['payment_status'] ?? null, fn ($query, $status) => $query->where('financial_status', $status === 'paid' ? 'settled' : 'pending')->where('status', 'delivered'));
        $rows = $query->latest('id')->paginate((int) ($data['per_page'] ?? 20));

        return response()->json([
            'data' => collect($rows->items())->map(fn (SalesOrder $order) => $this->payload($order))->values(),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total()],
            'stats' => [
                'open' => SalesOrder::query()->where('tenant_id', $tenant->id)->where('status', 'open')->count(),
                'receivable' => SalesOrder::query()->where('tenant_id', $tenant->id)->where('status', 'delivered')->where('financial_status', 'pending')->count(),
            ],
        ]);
    }

    public function show(Request $request, Tenant $tenant, SalesOrder $salesOrder, PlatformAccessPolicy $policy): JsonResponse
    {
        $this->authorizeOrder($request, $tenant, $salesOrder, $policy, false);

        return response()->json(['data' => $this->payload($salesOrder->load(['lines.catalogItem', 'payments']))]);
    }

    public function store(Request $request, Tenant $tenant, PlatformAccessPolicy $policy, PlatformAuditLogger $audit): JsonResponse
    {
        abort_unless($policy->canOperateTenant($request->user(), $tenant), 403);
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:120'],
            'core_sucursal_id' => ['nullable', 'integer', 'min:1'],
            'core_sucursal_code' => ['nullable', 'string', 'max:30'],
            'core_sucursal_name' => ['nullable', 'string', 'max:160'],
            'customer.core_customer_id' => ['nullable', 'integer', 'min:1'],
            'customer.name' => ['required', 'string', 'max:160'],
            'customer.phone' => ['nullable', 'string', 'max:40'],
            'customer.email' => ['nullable', 'email', 'max:160'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.catalog_item_id' => ['nullable', 'integer', Rule::exists('catalog_items', 'id')->where('tenant_id', $tenant->id)],
            'lines.*.description' => ['required_without:lines.*.catalog_item_id', 'nullable', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ]);

        $order = DB::transaction(function () use ($tenant, $request, $data): SalesOrder {
            $existing = SalesOrder::query()->where('tenant_id', $tenant->id)->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($existing) {
                $existing->wasRecentlyCreated = false;

                return $existing->load(['lines.catalogItem', 'payments']);
            }
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $number = (int) SalesOrder::query()->where('tenant_id', $tenant->id)->max('order_number') + 1;
            $prepared = collect($data['lines'])->map(function (array $line) use ($tenant): array {
                $item = ! empty($line['catalog_item_id']) ? CatalogItem::query()->where('tenant_id', $tenant->id)->findOrFail($line['catalog_item_id']) : null;
                $quantity = round((float) $line['quantity'], 3);
                $unitPrice = round((float) $line['unit_price'], 4);
                $gross = round($quantity * $unitPrice, 2);
                $discount = round((float) ($line['discount_amount'] ?? 0), 2);
                if ($discount > $gross) {
                    throw ValidationException::withMessages(['lines' => 'El descuento no puede superar el valor del artículo.']);
                }

                return ['item' => $item, 'quantity' => $quantity, 'unit_price' => $unitPrice, 'discount' => $discount, 'total' => round($gross - $discount, 2), 'description' => trim((string) ($line['description'] ?? $item?->name ?? ''))];
            });
            $subtotal = round((float) $prepared->sum(fn ($line) => $line['quantity'] * $line['unit_price']), 2);
            $discount = round((float) $prepared->sum('discount'), 2);
            $order = SalesOrder::query()->create([
                'tenant_id' => $tenant->id, 'idempotency_key' => $data['idempotency_key'], 'order_number' => $number,
                'core_sucursal_id' => $data['core_sucursal_id'] ?? null, 'core_sucursal_code' => $data['core_sucursal_code'] ?? null, 'core_sucursal_name' => $data['core_sucursal_name'] ?? null,
                'core_customer_id' => $data['customer']['core_customer_id'] ?? null, 'customer_name' => trim($data['customer']['name']), 'customer_phone' => $data['customer']['phone'] ?? null, 'customer_email' => $data['customer']['email'] ?? null,
                'subtotal' => $subtotal, 'discount_total' => $discount, 'total' => round($subtotal - $discount, 2), 'notes' => $data['notes'] ?? null, 'created_by' => $request->user()->id,
            ]);
            foreach ($prepared as $line) {
                $item = $line['item'];
                $order->lines()->create([
                    'tenant_id' => $tenant->id, 'catalog_item_id' => $item?->id, 'line_origin' => $item ? ($item->controls_inventory ? 'inventory' : 'catalog') : 'free',
                    'description_snapshot' => $line['description'], 'sku_snapshot' => $item?->sku, 'unit_code' => $item?->unit_code ?? '59', 'taxable' => $item?->taxable ?? true, 'price_includes_tax' => $item?->base_price_includes_tax ?? false,
                    'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'], 'discount_amount' => $line['discount'], 'line_total' => $line['total'], 'reference_unit_cost' => $item?->reference_cost ?? 0,
                ]);
            }
            $order->wasRecentlyCreated = true;

            return $order->load(['lines.catalogItem', 'payments']);
        });
        $audit->record($request, 'sales_order.created', ['sales_order_id' => $order->id]);

        return response()->json(['data' => $this->payload($order)], $order->wasRecentlyCreated ? 201 : 200);
    }

    public function deliver(Request $request, Tenant $tenant, SalesOrder $salesOrder, PlatformAccessPolicy $policy, InventoryService $inventory, CashService $cash, PlatformAuditLogger $audit): JsonResponse
    {
        $this->authorizeOrder($request, $tenant, $salesOrder, $policy);
        $data = $request->validate([
            'amount_received' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'method' => ['nullable', Rule::in(['cash', 'card', 'transfer', 'other'])],
            'reference' => ['nullable', 'string', 'max:160'],
            'document_choice' => ['required', Rule::in(['order', 'dte'])],
            'dte_type' => ['nullable', Rule::in(['01', '03'])],
        ]);
        $order = DB::transaction(function () use ($tenant, $salesOrder, $request, $data, $inventory, $cash): SalesOrder {
            $locked = SalesOrder::query()->whereKey($salesOrder->id)->with(['lines.catalogItem', 'payments'])->lockForUpdate()->firstOrFail();
            if ($locked->status === 'delivered') {
                return $locked;
            }
            abort_unless($locked->status === 'open', 422, 'Esta orden ya no puede entregarse.');
            $amount = round((float) ($data['amount_received'] ?? 0), 2);
            if ($amount > (float) $locked->total) {
                throw ValidationException::withMessages(['amount_received' => 'El monto recibido no puede superar el total de la orden.']);
            }
            if ($amount > 0 && empty($data['method'])) {
                throw ValidationException::withMessages(['method' => 'Selecciona la forma de pago.']);
            }
            $number = $this->number($locked);
            $inventoryLines = $locked->lines->where('line_origin', 'inventory');
            if ($inventoryLines->isNotEmpty()) {
                try {
                    $reservation = $inventory->reserve($tenant, [
                        'idempotency_key' => 'sales-order-delivery:'.$locked->id, 'source_type' => 'sales_order', 'source_id' => (string) $locked->id, 'source_number' => $number,
                        'core_sucursal_id' => $locked->core_sucursal_id, 'core_sucursal_code' => $locked->core_sucursal_code, 'core_sucursal_name' => $locked->core_sucursal_name,
                        'lines' => $inventoryLines->map(fn ($line) => ['catalog_item_id' => $line->catalog_item_id, 'quantity' => (float) $line->quantity, 'description' => $line->description_snapshot])->values()->all(),
                    ], $request->user()->id);
                    $inventory->confirmReservation($tenant, $reservation, ['source_type' => 'sales_order', 'source_id' => (string) $locked->id, 'source_number' => $number], $request->user()->id);
                } catch (InvalidArgumentException $exception) {
                    throw ValidationException::withMessages(['lines' => $exception->getMessage()]);
                }
            }
            if ($amount > 0) {
                $payment = $locked->payments()->firstOrCreate(
                    ['tenant_id' => $tenant->id, 'idempotency_key' => 'sales-order-delivery:'.$locked->id],
                    ['received_by' => $request->user()->id, 'amount' => $amount, 'method' => $data['method'], 'reference' => $data['reference'] ?? null, 'notes' => 'Cobro al entregar', 'received_at' => now()],
                );
                $cash->recordSalesOrderPayment($tenant, $payment);
            }
            $remaining = round((float) $locked->total - $amount, 2);
            $wantsDte = $data['document_choice'] === 'dte';
            $locked->forceFill(['status' => 'delivered', 'financial_status' => $remaining > 0 ? 'pending' : 'settled', 'billing_status' => $wantsDte ? 'pending' : 'unbilled', 'dte_type' => $wantsDte ? ($data['dte_type'] ?? '01') : null, 'delivered_by' => $request->user()->id, 'delivered_at' => now()])->save();
            $this->recordCommercialSale($tenant, $locked->load(['lines', 'payments']), $inventory, $request->user()->id);

            return $locked->refresh()->load(['lines.catalogItem', 'payments']);
        });
        $audit->record($request, 'sales_order.delivered', ['sales_order_id' => $order->id, 'balance' => $this->balance($order)]);

        return response()->json(['data' => $this->payload($order)]);
    }

    public function payment(Request $request, Tenant $tenant, SalesOrder $salesOrder, PlatformAccessPolicy $policy, InventoryService $inventory, CashService $cash, PlatformAuditLogger $audit): JsonResponse
    {
        $this->authorizeOrder($request, $tenant, $salesOrder, $policy);
        $data = $request->validate(['idempotency_key' => ['required', 'string', 'max:120'], 'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999'], 'method' => ['required', Rule::in(['cash', 'card', 'transfer', 'other'])], 'reference' => ['nullable', 'string', 'max:160'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $order = DB::transaction(function () use ($tenant, $salesOrder, $request, $data, $inventory, $cash): SalesOrder {
            $locked = SalesOrder::query()->whereKey($salesOrder->id)->with(['lines.catalogItem', 'payments'])->lockForUpdate()->firstOrFail();
            $existingPayment = SalesOrderPayment::query()
                ->where('tenant_id', $tenant->id)
                ->where('idempotency_key', $data['idempotency_key'])
                ->lockForUpdate()
                ->first();
            if ($existingPayment) {
                abort_unless($existingPayment->sales_order_id === $locked->id, 409, 'Esta operación ya fue utilizada en otra orden.');

                return $locked;
            }
            abort_unless($locked->status === 'delivered' && $locked->financial_status === 'pending', 422, 'Esta orden no tiene saldo pendiente.');
            $balance = $this->balance($locked);
            if ((float) $data['amount'] > $balance) {
                throw ValidationException::withMessages(['amount' => 'El pago no puede superar el saldo pendiente.']);
            }
            $payment = $locked->payments()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'idempotency_key' => $data['idempotency_key']],
                ['received_by' => $request->user()->id, 'amount' => $data['amount'], 'method' => $data['method'], 'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null, 'received_at' => now()],
            );
            $cash->recordSalesOrderPayment($tenant, $payment);
            if ($this->balance($locked->refresh()->load('payments')) <= 0) {
                $locked->forceFill(['financial_status' => 'settled'])->save();
            }
            $this->recordCommercialSale($tenant, $locked->load(['lines', 'payments']), $inventory, $request->user()->id);

            return $locked->refresh()->load(['lines.catalogItem', 'payments']);
        });
        $audit->record($request, 'sales_order.payment_received', ['sales_order_id' => $order->id, 'balance' => $this->balance($order)]);

        return response()->json(['data' => $this->payload($order)]);
    }

    public function cancel(Request $request, Tenant $tenant, SalesOrder $salesOrder, PlatformAccessPolicy $policy, PlatformAuditLogger $audit): JsonResponse
    {
        $this->authorizeOrder($request, $tenant, $salesOrder, $policy);
        abort_unless($salesOrder->status === 'open', 422, 'Solo una orden sin entregar puede cancelarse.');
        $salesOrder->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
        $audit->record($request, 'sales_order.cancelled', ['sales_order_id' => $salesOrder->id]);

        return response()->json(['data' => $this->payload($salesOrder->refresh()->load(['lines.catalogItem', 'payments']))]);
    }

    private function recordCommercialSale(Tenant $tenant, SalesOrder $order, InventoryService $inventory, ?int $userId): void
    {
        $balance = $this->balance($order);
        $inventory->recordSale($tenant, [
            'source_type' => 'sales_order', 'source_id' => (string) $order->id, 'source_number' => $this->number($order), 'sale_date' => $order->delivered_at?->toDateString() ?? now()->toDateString(),
            'core_sucursal_id' => $order->core_sucursal_id, 'core_sucursal_code' => $order->core_sucursal_code, 'core_sucursal_name' => $order->core_sucursal_name,
            'net_amount' => (float) $order->total, 'tax_amount' => 0, 'total_amount' => (float) $order->total,
            'metadata' => ['customer_id' => $order->core_customer_id, 'customer_name' => $order->customer_name, 'payment_status' => $balance > 0 ? 'receivable' : 'paid', 'outstanding_amount' => $balance, 'billing_status' => $order->billing_status],
            'lines' => $order->lines->map(fn ($line) => ['catalog_item_id' => $line->catalog_item_id, 'line_origin' => $line->line_origin, 'description' => $line->description_snapshot, 'quantity' => (float) $line->quantity, 'unit_price' => (float) $line->unit_price, 'discount_amount' => (float) $line->discount_amount, 'net_total' => (float) $line->line_total, 'tax_amount' => 0, 'total_amount' => (float) $line->line_total, 'reference_unit_cost' => (float) $line->reference_unit_cost])->all(),
        ], $userId);
    }

    private function authorizeOrder(Request $request, Tenant $tenant, SalesOrder $order, PlatformAccessPolicy $policy, bool $operate = true): void
    {
        abort_unless($order->tenant_id === $tenant->id, 404);
        abort_unless($operate ? $policy->canOperateTenant($request->user(), $tenant) : $policy->canViewTenantCatalog($request->user(), $tenant), 403);
    }

    private function balance(SalesOrder $order): float
    {
        $paid = (float) $order->payments->sum('amount');

        return max(0, round((float) $order->total - $paid, 2));
    }

    private function number(SalesOrder $order): string
    {
        return 'OV-'.str_pad((string) $order->order_number, 6, '0', STR_PAD_LEFT);
    }

    private function payload(SalesOrder $order): array
    {
        $order->loadMissing(['lines.catalogItem', 'payments']);
        $paid = round((float) $order->payments->sum('amount'), 2);

        return [
            'id' => $order->id, 'number' => $this->number($order), 'status' => $order->status,
            'branch' => ['id' => $order->core_sucursal_id, 'code' => $order->core_sucursal_code, 'name' => $order->core_sucursal_name],
            'customer' => ['id' => $order->core_customer_id, 'name' => $order->customer_name, 'phone' => $order->customer_phone, 'email' => $order->customer_email],
            'subtotal' => (float) $order->subtotal, 'discount_total' => (float) $order->discount_total, 'total' => (float) $order->total, 'paid_total' => $paid, 'balance' => max(0, round((float) $order->total - $paid, 2)), 'notes' => $order->notes,
            'financial_status' => $order->financial_status, 'billing' => ['status' => $order->billing_status, 'dte_type' => $order->dte_type, 'core_document_id' => $order->core_dte_document_id, 'number' => $order->dte_number, 'generation_code' => $order->dte_generation_code, 'invoiced_at' => $order->invoiced_at?->toISOString()],
            'lines' => $order->lines->map(fn ($line) => ['id' => $line->id, 'catalog_item_id' => $line->catalog_item_id, 'line_origin' => $line->line_origin, 'description' => $line->description_snapshot, 'sku' => $line->sku_snapshot, 'unit_code' => $line->unit_code, 'taxable' => $line->taxable, 'price_includes_tax' => $line->price_includes_tax, 'quantity' => (float) $line->quantity, 'unit_price' => (float) $line->unit_price, 'discount_amount' => (float) $line->discount_amount, 'total' => (float) $line->line_total])->values(),
            'payments' => $order->payments->map(fn (SalesOrderPayment $payment) => ['id' => $payment->id, 'amount' => (float) $payment->amount, 'method' => $payment->method, 'reference' => $payment->reference, 'received_at' => $payment->received_at?->toISOString()])->values(),
            'created_at' => $order->created_at?->toISOString(), 'delivered_at' => $order->delivered_at?->toISOString(), 'cancelled_at' => $order->cancelled_at?->toISOString(),
        ];
    }
}

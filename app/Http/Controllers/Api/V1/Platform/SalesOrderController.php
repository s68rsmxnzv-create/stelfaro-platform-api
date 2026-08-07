<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\SalesOrder;
use App\Models\SalesOrderPayment;
use App\Models\Tenant;
use App\Services\Cash\CashService;
use App\Services\Inventory\InventoryService;
use App\Services\PlatformAccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SalesOrderController extends Controller
{
    public function index(Request $request, Tenant $tenant, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canViewTenantCatalog($request->user(), $tenant), 403);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'status' => ['nullable', 'string', 'max:24'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:5', 'max:100']]);
        $query = SalesOrder::query()->where('tenant_id', $tenant->id)->with(['lines', 'payments', 'statusEvents']);
        $query->when($data['q'] ?? null, fn ($q, $term) => $q->where(fn ($nested) => $nested->whereLike('customer_name', "%{$term}%")->orWhereLike('title', "%{$term}%")->orWhere('order_number', $term)));
        $query->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status));
        $orders = $query->latest()->paginate((int) ($data['per_page'] ?? 20));

        return response()->json(['data' => collect($orders->items())->map(fn (SalesOrder $order) => $this->payload($order))->values(), 'meta' => ['current_page' => $orders->currentPage(), 'last_page' => $orders->lastPage(), 'total' => $orders->total()]]);
    }

    public function show(Request $request, Tenant $tenant, SalesOrder $salesOrder, PlatformAccessPolicy $policy): JsonResponse
    {
        $this->authorize($request, $tenant, $salesOrder, $policy);

        return response()->json(['data' => $this->payload($salesOrder->load(['lines', 'payments']))]);
    }

    public function store(Request $request, Tenant $tenant, PlatformAccessPolicy $policy, CashService $cash): JsonResponse
    {
        abort_unless($policy->canOperateTenant($request->user(), $tenant), 403);
        $data = $this->validated($request);
        if (! empty($data['idempotency_key'])) {
            $existing = SalesOrder::query()->where('tenant_id', $tenant->id)->where('idempotency_key', $data['idempotency_key'])->with(['lines', 'payments', 'statusEvents'])->first();
            if ($existing) {
                return response()->json(['data' => $this->payload($existing)]);
            }
        }
        $order = DB::transaction(function () use ($data, $tenant, $request, $cash): SalesOrder {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $totals = $this->totals($data['lines']);
            throw_if((float) ($data['deposit']['amount'] ?? 0) > $totals['total'], ValidationException::withMessages(['deposit.amount' => 'El anticipo no puede superar el total de la orden.']));
            $order = SalesOrder::query()->create([
                'tenant_id' => $tenant->id,
                'idempotency_key' => $data['idempotency_key'] ?? (string) Str::uuid(),
                'order_number' => ((int) SalesOrder::query()->where('tenant_id', $tenant->id)->max('order_number')) + 1,
                'core_sucursal_id' => $data['core_sucursal_id'] ?? null, 'core_sucursal_code' => $data['core_sucursal_code'] ?? null, 'core_sucursal_name' => $data['core_sucursal_name'] ?? null,
                'core_customer_id' => $data['customer']['id'] ?? null, 'customer_name' => $data['customer']['name'], 'customer_phone' => $data['customer']['phone'] ?? null, 'customer_email' => $data['customer']['email'] ?? null,
                'title' => $data['title'], 'work_type' => $data['work_type'] ?? 'custom', 'status' => 'open',
                'financial_status' => $totals['total'] > 0 ? 'pending' : 'settled', 'billing_status' => 'unbilled',
                ...$totals, 'due_at' => $data['due_at'] ?? null, 'notes' => $data['notes'] ?? null, 'created_by' => $request->user()->id,
            ]);
            $this->replaceLines($order, $data['lines']);
            $this->recordStatus($order, null, 'open', $request->user()->id, 'Orden creada.');
            if ((float) ($data['deposit']['amount'] ?? 0) > 0) {
                $this->payment($order, $tenant, $request, $cash, [...$data['deposit'], 'kind' => 'payment', 'notes' => $data['deposit']['notes'] ?? 'Anticipo al crear la orden']);
                $order->forceFill(['financial_status' => $this->balance($order->refresh()) <= 0 ? 'settled' : 'pending'])->save();
            }

            return $order->refresh()->load(['lines', 'payments', 'statusEvents']);
        });

        return response()->json(['data' => $this->payload($order)], 201);
    }

    public function update(Request $request, Tenant $tenant, SalesOrder $salesOrder, PlatformAccessPolicy $policy, InventoryService $inventory): JsonResponse
    {
        $this->authorize($request, $tenant, $salesOrder, $policy, true);
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:180'], 'status' => ['nullable', Rule::in(['approved', 'in_progress', 'ready', 'delivered'])], 'transition_note' => ['nullable', 'string', 'max:1000'], 'notes' => ['nullable', 'string', 'max:5000'], 'due_at' => ['nullable', 'date'],
            'customer' => ['nullable', 'array'], 'customer.id' => ['nullable', 'integer'], 'customer.name' => ['required_with:customer', 'string', 'max:160'], 'customer.phone' => ['nullable', 'string', 'max:40'], 'customer.email' => ['nullable', 'email', 'max:160'],
            'core_sucursal_id' => ['nullable', 'integer'], 'core_sucursal_code' => ['nullable', 'string', 'max:30'], 'core_sucursal_name' => ['nullable', 'string', 'max:160'],
            'lines' => ['nullable', 'array', 'min:1'], 'lines.*.catalog_item_id' => ['nullable', 'integer'], 'lines.*.description' => ['required_with:lines', 'string', 'max:255'], 'lines.*.quantity' => ['required_with:lines', 'numeric', 'gt:0'], 'lines.*.unit_price' => ['required_with:lines', 'numeric', 'min:0'], 'lines.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
        ]);
        $salesOrder = DB::transaction(function () use ($salesOrder, $data, $tenant, $request, $inventory): SalesOrder {
            $locked = SalesOrder::query()->where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($salesOrder->id);
            abort_if($locked->status === 'cancelled', 422, 'La orden cancelada no puede modificarse.');
            $editingCommercialData = array_key_exists('lines', $data) || array_key_exists('customer', $data) || array_key_exists('core_sucursal_id', $data);
            abort_if($editingCommercialData && (! in_array($locked->status, ['open', 'approved'], true) || $locked->billing_status === 'invoiced'), 422, 'Solo puedes editar el contenido antes de iniciar o facturar el trabajo.');
            if (isset($data['lines'])) {
                $totals = $this->totals($data['lines']);
                throw_if($totals['total'] < $this->netPaid($locked), ValidationException::withMessages(['lines' => 'El nuevo total no puede ser menor que lo recibido.']));
                $locked->lines()->delete();
                $this->replaceLines($locked, $data['lines']);
                $locked->fill($totals);
            }
            if (isset($data['customer'])) {
                $locked->fill(['core_customer_id' => $data['customer']['id'] ?? null, 'customer_name' => $data['customer']['name'], 'customer_phone' => $data['customer']['phone'] ?? null, 'customer_email' => $data['customer']['email'] ?? null]);
            }
            foreach (['title', 'notes', 'due_at', 'core_sucursal_id', 'core_sucursal_code', 'core_sucursal_name'] as $field) {
                if (array_key_exists($field, $data)) {
                    $locked->{$field} = $data[$field];
                }
            }
            if (isset($data['status']) && $data['status'] !== $locked->status) {
                $this->assertTransition($locked->status, $data['status']);
                $from = $locked->status;
                $locked->status = $data['status'];
                if ($data['status'] === 'ready') {
                    $locked->completed_at = now();
                }
                if ($data['status'] === 'delivered') {
                    $locked->delivered_at = now();
                    $locked->delivered_by = $request->user()->id;
                }
                $locked->save();
                $this->recordStatus($locked, $from, $data['status'], $request->user()->id, $data['transition_note'] ?? null);
            } else {
                $locked->save();
            }
            if ($locked->status === 'delivered') {
                $locked->load(['lines', 'payments']);
                $inventory->recordSale($tenant, [
                    'source_type' => 'sales_order', 'source_id' => (string) $locked->id, 'source_number' => 'OT-'.str_pad((string) $locked->order_number, 6, '0', STR_PAD_LEFT),
                    'core_sucursal_id' => $locked->core_sucursal_id, 'core_sucursal_code' => $locked->core_sucursal_code, 'core_sucursal_name' => $locked->core_sucursal_name,
                    'sale_date' => now()->toDateString(), 'metadata' => ['customer_id' => $locked->core_customer_id, 'customer_name' => $locked->customer_name, 'payment_status' => $this->balance($locked) > 0 ? 'receivable' : 'paid', 'outstanding_amount' => $this->balance($locked), 'billing_status' => $locked->billing_status],
                    'lines' => $locked->lines->map(fn ($line) => ['catalog_item_id' => $line->catalog_item_id, 'description' => $line->description_snapshot, 'quantity' => (float) $line->quantity, 'unit_price' => (float) $line->unit_price, 'discount_amount' => (float) $line->discount_amount, 'net_total' => (float) $line->line_total, 'line_origin' => $line->line_origin])->all(),
                ], $request->user()->id);
            }

            return $locked->refresh()->load(['lines', 'payments', 'statusEvents']);
        });

        return response()->json(['data' => $this->payload($salesOrder)]);
    }

    public function recordPayment(Request $request, Tenant $tenant, SalesOrder $salesOrder, PlatformAccessPolicy $policy, CashService $cash, InventoryService $inventory): JsonResponse
    {
        $this->authorize($request, $tenant, $salesOrder, $policy, true);
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0.01'], 'method' => ['required', Rule::in(['cash', 'card', 'transfer', 'other'])], 'reference' => ['nullable', 'string', 'max:160'], 'notes' => ['nullable', 'string', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:120']]);
        $salesOrder = DB::transaction(function () use ($salesOrder, $tenant, $request, $cash, $data): SalesOrder {
            $locked = SalesOrder::query()->where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($salesOrder->id);
            abort_if($locked->status === 'cancelled', 422, 'No puedes cobrar una orden cancelada.');
            $existing = SalesOrderPayment::query()->where('tenant_id', $tenant->id)->where('idempotency_key', $data['idempotency_key'])->first();
            abort_if($existing && $existing->sales_order_id !== $locked->id, 409, 'La clave de esta operación ya fue utilizada en otra orden.');
            if (! $existing) {
                throw_if((float) $data['amount'] > $this->balance($locked), ValidationException::withMessages(['amount' => 'El pago supera el saldo pendiente.']));
                $this->payment($locked, $tenant, $request, $cash, [...$data, 'kind' => 'payment']);
            }
            $locked->forceFill(['financial_status' => $this->balance($locked->refresh()) <= 0 ? 'settled' : 'pending'])->save();

            return $locked->refresh()->load(['lines', 'payments', 'statusEvents']);
        });
        if ($salesOrder->status === 'delivered') {
            $inventory->recordSale($tenant, ['source_type' => 'sales_order', 'source_id' => (string) $salesOrder->id, 'source_number' => 'OT-'.str_pad((string) $salesOrder->order_number, 6, '0', STR_PAD_LEFT), 'metadata' => ['payment_status' => $this->balance($salesOrder) <= 0 ? 'paid' : 'receivable', 'outstanding_amount' => $this->balance($salesOrder)], 'lines' => []], $request->user()->id);
        }

        return response()->json(['data' => $this->payload($salesOrder)]);
    }

    public function cancel(Request $request, Tenant $tenant, SalesOrder $salesOrder, PlatformAccessPolicy $policy, CashService $cash, InventoryService $inventory): JsonResponse
    {
        $this->authorize($request, $tenant, $salesOrder, $policy, true);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000'], 'retained_amount' => ['nullable', 'numeric', 'min:0'], 'method' => ['nullable', Rule::in(['cash', 'card', 'transfer', 'other'])], 'reference' => ['nullable', 'string', 'max:160']]);
        DB::transaction(function () use ($salesOrder, $data, $tenant, $request, $cash, $inventory): void {
            $salesOrder = SalesOrder::query()->where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($salesOrder->id);
            abort_if($salesOrder->status === 'cancelled', 422, 'La orden ya fue cancelada.');
            abort_if($salesOrder->status === 'delivered', 422, 'Una orden entregada requiere una devolución comercial, no cancelación directa.');
            abort_if($salesOrder->billing_status === 'invoiced' || $salesOrder->core_dte_document_id, 422, 'La orden facturada requiere invalidar o ajustar el DTE antes de cancelarla.');
            $paid = $this->netPaid($salesOrder);
            $retained = min($paid, round((float) ($data['retained_amount'] ?? 0), 2));
            $refund = round($paid - $retained, 2);
            if ($refund > 0 && empty($data['method'])) {
                throw ValidationException::withMessages(['method' => 'Selecciona cómo se devolverá el anticipo.']);
            }
            $from = $salesOrder->status;
            $salesOrder->forceFill(['status' => 'cancelled', 'financial_status' => 'cancelled', 'cancellation_reason' => $data['reason'], 'cancelled_at' => now()])->save();
            $this->recordStatus($salesOrder, $from, 'cancelled', $request->user()->id, $data['reason']);
            if ($refund > 0) {
                $this->payment($salesOrder, $tenant, $request, $cash, ['kind' => 'refund', 'amount' => $refund, 'method' => $data['method'], 'reference' => $data['reference'] ?? null, 'notes' => 'Devolución por cancelación: '.$data['reason']]);
            }
            if ($retained > 0) {
                $inventory->recordSale($tenant, [
                    'source_type' => 'sales_order_cancellation',
                    'source_id' => (string) $salesOrder->id,
                    'source_number' => 'OT-'.str_pad((string) $salesOrder->order_number, 6, '0', STR_PAD_LEFT),
                    'sale_date' => now()->toDateString(),
                    'metadata' => ['payment_status' => 'paid', 'cancellation_reason' => $data['reason']],
                    'lines' => [[
                        'description' => 'Retención por cancelación de '.$salesOrder->title,
                        'quantity' => 1,
                        'unit_price' => $retained,
                        'discount_amount' => 0,
                        'net_total' => $retained,
                        'line_origin' => 'service',
                    ]],
                ], $request->user()->id);
            }
        });

        return response()->json(['data' => $this->payload($salesOrder->refresh()->load(['lines', 'payments', 'statusEvents']))]);
    }

    public function linkInvoice(Request $request, Tenant $tenant, SalesOrder $salesOrder, PlatformAccessPolicy $policy): JsonResponse
    {
        $this->authorize($request, $tenant, $salesOrder, $policy, true);
        $data = $request->validate(['core_dte_document_id' => ['required', 'integer'], 'dte_number' => ['required', 'string', 'max:80'], 'dte_generation_code' => ['required', 'string', 'max:80'], 'dte_type' => ['required', Rule::in(['01', '03'])]]);
        abort_if($salesOrder->core_dte_document_id && (int) $salesOrder->core_dte_document_id !== (int) $data['core_dte_document_id'], 422, 'La orden ya está vinculada a otro DTE.');
        $salesOrder->forceFill([...$data, 'billing_status' => 'invoiced', 'invoiced_at' => now()])->save();

        return response()->json(['data' => $this->payload($salesOrder->refresh()->load(['lines', 'payments']))]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'idempotency_key' => ['nullable', 'string', 'max:120'], 'title' => ['required', 'string', 'max:180'], 'work_type' => ['nullable', 'string', 'max:40'],
            'customer.id' => ['nullable', 'integer'], 'customer.name' => ['required', 'string', 'max:160'], 'customer.phone' => ['nullable', 'string', 'max:40'], 'customer.email' => ['nullable', 'email', 'max:160'],
            'core_sucursal_id' => ['nullable', 'integer'], 'core_sucursal_code' => ['nullable', 'string', 'max:30'], 'core_sucursal_name' => ['nullable', 'string', 'max:160'], 'due_at' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.catalog_item_id' => ['nullable', 'integer'], 'lines.*.description' => ['required', 'string', 'max:255'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0'], 'lines.*.unit_price' => ['required', 'numeric', 'min:0'], 'lines.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'deposit.amount' => ['nullable', 'numeric', 'min:0'], 'deposit.method' => ['required_with:deposit.amount', Rule::in(['cash', 'card', 'transfer', 'other'])], 'deposit.reference' => ['nullable', 'string', 'max:160'], 'deposit.notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function totals(array $lines): array
    {
        $subtotal = collect($lines)->sum(fn ($line) => round((float) $line['quantity'] * (float) $line['unit_price'], 2));
        $discount = collect($lines)->sum(function ($line): float {
            $gross = round((float) $line['quantity'] * (float) $line['unit_price'], 2);

            return min($gross, round((float) ($line['discount_amount'] ?? 0), 2));
        });

        return ['subtotal' => round($subtotal, 2), 'discount_total' => round($discount, 2), 'total' => max(0, round($subtotal - $discount, 2))];
    }

    private function replaceLines(SalesOrder $order, array $lines): void
    {
        foreach ($lines as $line) {
            $gross = round((float) $line['quantity'] * (float) $line['unit_price'], 2);
            $discount = min($gross, round((float) ($line['discount_amount'] ?? 0), 2));
            $order->lines()->create(['tenant_id' => $order->tenant_id, 'catalog_item_id' => $line['catalog_item_id'] ?? null, 'line_origin' => isset($line['catalog_item_id']) ? 'catalog' : 'free', 'description_snapshot' => $line['description'], 'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'], 'discount_amount' => $discount, 'line_total' => $gross - $discount]);
        }
    }

    private function payment(SalesOrder $order, Tenant $tenant, Request $request, CashService $cash, array $data): void
    {
        $payment = $order->payments()->firstOrCreate(['tenant_id' => $tenant->id, 'idempotency_key' => $data['idempotency_key'] ?? (string) Str::uuid()], ['received_by' => $request->user()->id, 'kind' => $data['kind'], 'amount' => $data['amount'], 'method' => $data['method'], 'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null, 'received_at' => now()]);
        $cash->recordSalesOrderPayment($tenant, $payment);
    }

    private function netPaid(SalesOrder $order): float
    {
        $payments = $order->relationLoaded('payments') ? $order->payments : $order->payments()->get();

        return round((float) $payments->whereNull('voided_at')->sum(fn ($payment) => $payment->kind === 'refund' ? -(float) $payment->amount : (float) $payment->amount), 2);
    }

    private function balance(SalesOrder $order): float
    {
        return max(0, round((float) $order->total - $this->netPaid($order), 2));
    }

    private function authorize(Request $request, Tenant $tenant, SalesOrder $order, PlatformAccessPolicy $policy, bool $operate = false): void
    {
        abort_unless($order->tenant_id === $tenant->id, 404);
        abort_unless($operate ? $policy->canOperateTenant($request->user(), $tenant) : $policy->canViewTenantCatalog($request->user(), $tenant), 403);
    }

    private function payload(SalesOrder $order): array
    {
        $paid = $this->netPaid($order);

        return ['id' => $order->id, 'number' => 'OT-'.str_pad((string) $order->order_number, 6, '0', STR_PAD_LEFT), 'title' => $order->title, 'work_type' => $order->work_type, 'status' => $order->status, 'financial_status' => $order->financial_status, 'branch' => ['id' => $order->core_sucursal_id, 'code' => $order->core_sucursal_code, 'name' => $order->core_sucursal_name], 'billing' => ['status' => $order->billing_status, 'dte_type' => $order->dte_type, 'core_document_id' => $order->core_dte_document_id, 'number' => $order->dte_number], 'customer' => ['id' => $order->core_customer_id, 'name' => $order->customer_name, 'phone' => $order->customer_phone, 'email' => $order->customer_email], 'subtotal' => (float) $order->subtotal, 'discount_total' => (float) $order->discount_total, 'total' => (float) $order->total, 'paid_total' => $paid, 'balance' => $order->status === 'cancelled' ? 0.0 : max(0, (float) $order->total - $paid), 'due_at' => $order->due_at?->toISOString(), 'notes' => $order->notes, 'cancellation_reason' => $order->cancellation_reason, 'lines' => $order->lines->map(fn ($line) => ['id' => $line->id, 'catalog_item_id' => $line->catalog_item_id, 'description' => $line->description_snapshot, 'quantity' => (float) $line->quantity, 'unit_price' => (float) $line->unit_price, 'discount_amount' => (float) $line->discount_amount, 'total' => (float) $line->line_total])->values(), 'timeline' => $order->statusEvents->sortByDesc('occurred_at')->map(fn ($event) => ['id' => $event->id, 'from' => $event->from_status, 'to' => $event->to_status, 'note' => $event->note, 'occurred_at' => $event->occurred_at?->toISOString()])->values(), 'created_at' => $order->created_at?->toISOString()];
    }

    private function assertTransition(string $from, string $to): void
    {
        $allowed = ['open' => ['approved'], 'approved' => ['in_progress'], 'in_progress' => ['ready'], 'ready' => ['delivered']];
        throw_unless(in_array($to, $allowed[$from] ?? [], true), ValidationException::withMessages(['status' => "No puedes cambiar una orden de {$from} a {$to}."]));
    }

    private function recordStatus(SalesOrder $order, ?string $from, string $to, ?int $userId, ?string $note = null): void
    {
        $order->statusEvents()->create(['tenant_id' => $order->tenant_id, 'from_status' => $from, 'to_status' => $to, 'note' => $note, 'created_by' => $userId, 'occurred_at' => now()]);
    }
}

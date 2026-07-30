<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\Tenant;
use App\Services\Cash\CashService;
use App\Services\PlatformAccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class QuotationController extends Controller
{
    public function index(Request $request, Tenant $tenant, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canViewTenantCatalog($request->user(), $tenant), 403);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'status' => ['nullable', 'string', 'max:24']]);
        $query = Quotation::query()->where('tenant_id', $tenant->id)->with(['lines', 'order']);
        $query->when($data['q'] ?? null, fn ($q, $term) => $q->where(fn ($nested) => $nested->where('customer_name', 'like', "%{$term}%")->orWhere('title', 'like', "%{$term}%")->orWhere('quotation_number', $term)));
        $query->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status));

        return response()->json(['data' => $query->latest()->limit(100)->get()->map(fn (Quotation $quotation) => $this->payload($quotation))->values()]);
    }

    public function store(Request $request, Tenant $tenant, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canOperateTenant($request->user(), $tenant), 403);
        $data = $this->validated($request);
        $quotation = DB::transaction(function () use ($data, $tenant, $request): Quotation {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $totals = $this->totals($data['lines']);
            $quotation = Quotation::query()->create([
                'tenant_id' => $tenant->id, 'idempotency_key' => $data['idempotency_key'] ?? (string) Str::uuid(),
                'quotation_number' => ((int) Quotation::query()->where('tenant_id', $tenant->id)->max('quotation_number')) + 1,
                'core_sucursal_id' => $data['core_sucursal_id'] ?? null, 'core_sucursal_code' => $data['core_sucursal_code'] ?? null, 'core_sucursal_name' => $data['core_sucursal_name'] ?? null,
                'core_customer_id' => $data['customer']['id'] ?? null, 'customer_name' => $data['customer']['name'], 'customer_phone' => $data['customer']['phone'] ?? null, 'customer_email' => $data['customer']['email'] ?? null,
                'title' => $data['title'], 'status' => 'draft', ...$totals, 'requested_deposit' => min($totals['total'], (float) ($data['requested_deposit'] ?? 0)),
                'valid_until' => $data['valid_until'] ?? null, 'terms' => $data['terms'] ?? null, 'notes' => $data['notes'] ?? null, 'created_by' => $request->user()->id,
            ]);
            foreach ($data['lines'] as $line) {
                $gross = round((float) $line['quantity'] * (float) $line['unit_price'], 2);
                $discount = min($gross, (float) ($line['discount_amount'] ?? 0));
                $quotation->lines()->create(['tenant_id' => $tenant->id, 'catalog_item_id' => $line['catalog_item_id'] ?? null, 'description_snapshot' => $line['description'], 'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'], 'discount_amount' => $discount, 'line_total' => $gross - $discount]);
            }

            return $quotation->refresh()->load('lines');
        });

        return response()->json(['data' => $this->payload($quotation)], 201);
    }

    public function status(Request $request, Tenant $tenant, Quotation $quotation, PlatformAccessPolicy $policy): JsonResponse
    {
        $this->authorize($request, $tenant, $quotation, $policy);
        $data = $request->validate(['status' => ['required', Rule::in(['draft', 'sent', 'accepted', 'rejected'])]]);
        abort_if($quotation->converted_at !== null, 422, 'La cotización ya fue convertida en orden.');
        $quotation->forceFill(['status' => $data['status'], 'sent_at' => $data['status'] === 'sent' ? now() : $quotation->sent_at, 'accepted_at' => $data['status'] === 'accepted' ? now() : null, 'rejected_at' => $data['status'] === 'rejected' ? now() : null])->save();

        return response()->json(['data' => $this->payload($quotation->refresh()->load(['lines', 'order']))]);
    }

    public function convert(Request $request, Tenant $tenant, Quotation $quotation, PlatformAccessPolicy $policy, CashService $cash): JsonResponse
    {
        $this->authorize($request, $tenant, $quotation, $policy);
        $data = $request->validate(['deposit.amount' => ['nullable', 'numeric', 'min:0'], 'deposit.method' => ['required_with:deposit.amount', Rule::in(['cash', 'card', 'transfer', 'other'])], 'deposit.reference' => ['nullable', 'string', 'max:160']]);
        abort_if($quotation->converted_at !== null, 422, 'La cotización ya fue convertida.');
        abort_unless(in_array($quotation->status, ['sent', 'accepted'], true), 422, 'Envía o acepta la cotización antes de convertirla.');
        $quotation->load('lines');
        $deposit = round((float) ($data['deposit']['amount'] ?? 0), 2);
        throw_if($deposit > (float) $quotation->total, ValidationException::withMessages(['deposit.amount' => 'El anticipo supera el total cotizado.']));
        $order = DB::transaction(function () use ($quotation, $tenant, $request, $cash, $data, $deposit): SalesOrder {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $order = SalesOrder::query()->create([
                'tenant_id' => $tenant->id, 'idempotency_key' => 'quotation:'.$quotation->id, 'quotation_id' => $quotation->id,
                'order_number' => ((int) SalesOrder::query()->where('tenant_id', $tenant->id)->max('order_number')) + 1,
                'core_sucursal_id' => $quotation->core_sucursal_id, 'core_sucursal_code' => $quotation->core_sucursal_code, 'core_sucursal_name' => $quotation->core_sucursal_name,
                'core_customer_id' => $quotation->core_customer_id, 'customer_name' => $quotation->customer_name, 'customer_phone' => $quotation->customer_phone, 'customer_email' => $quotation->customer_email,
                'title' => $quotation->title, 'work_type' => 'quotation', 'status' => 'approved', 'financial_status' => $deposit >= (float) $quotation->total ? 'settled' : 'pending', 'billing_status' => 'unbilled',
                'subtotal' => $quotation->subtotal, 'discount_total' => $quotation->discount_total, 'total' => $quotation->total, 'notes' => $quotation->notes, 'created_by' => $request->user()->id,
            ]);
            foreach ($quotation->lines as $line) {
                $order->lines()->create(['tenant_id' => $tenant->id, 'catalog_item_id' => $line->catalog_item_id, 'line_origin' => $line->catalog_item_id ? 'catalog' : 'free', 'description_snapshot' => $line->description_snapshot, 'unit_code' => $line->unit_code, 'quantity' => $line->quantity, 'unit_price' => $line->unit_price, 'discount_amount' => $line->discount_amount, 'line_total' => $line->line_total]);
            }
            if ($deposit > 0) {
                $payment = $order->payments()->create(['tenant_id' => $tenant->id, 'idempotency_key' => 'quotation-deposit:'.$quotation->id, 'received_by' => $request->user()->id, 'kind' => 'payment', 'amount' => $deposit, 'method' => $data['deposit']['method'], 'reference' => $data['deposit']['reference'] ?? null, 'notes' => 'Anticipo de cotización C-'.str_pad((string) $quotation->quotation_number, 6, '0', STR_PAD_LEFT), 'received_at' => now()]);
                $cash->recordSalesOrderPayment($tenant, $payment);
            }
            $quotation->forceFill(['status' => 'converted', 'accepted_at' => $quotation->accepted_at ?? now(), 'converted_at' => now()])->save();

            return $order->refresh()->load(['lines', 'payments']);
        });

        return response()->json(['data' => ['order_id' => $order->id, 'order_number' => 'OT-'.str_pad((string) $order->order_number, 6, '0', STR_PAD_LEFT)]]);
    }

    private function validated(Request $request): array
    {
        return $request->validate(['idempotency_key' => ['nullable', 'string', 'max:120'], 'title' => ['required', 'string', 'max:180'], 'customer.id' => ['nullable', 'integer'], 'customer.name' => ['required', 'string', 'max:160'], 'customer.phone' => ['nullable', 'string', 'max:40'], 'customer.email' => ['nullable', 'email', 'max:160'], 'core_sucursal_id' => ['nullable', 'integer'], 'core_sucursal_code' => ['nullable', 'string', 'max:30'], 'core_sucursal_name' => ['nullable', 'string', 'max:160'], 'requested_deposit' => ['nullable', 'numeric', 'min:0'], 'valid_until' => ['nullable', 'date'], 'terms' => ['nullable', 'string', 'max:5000'], 'notes' => ['nullable', 'string', 'max:5000'], 'lines' => ['required', 'array', 'min:1'], 'lines.*.catalog_item_id' => ['nullable', 'integer'], 'lines.*.description' => ['required', 'string', 'max:255'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0'], 'lines.*.unit_price' => ['required', 'numeric', 'min:0'], 'lines.*.discount_amount' => ['nullable', 'numeric', 'min:0']]);
    }

    private function totals(array $lines): array
    {
        $subtotal = collect($lines)->sum(fn ($line) => round((float) $line['quantity'] * (float) $line['unit_price'], 2));
        $discount = collect($lines)->sum(fn ($line) => (float) ($line['discount_amount'] ?? 0));

        return ['subtotal' => round($subtotal, 2), 'discount_total' => round($discount, 2), 'total' => max(0, round($subtotal - $discount, 2))];
    }

    private function authorize(Request $request, Tenant $tenant, Quotation $quotation, PlatformAccessPolicy $policy): void
    {
        abort_unless($quotation->tenant_id === $tenant->id, 404);
        abort_unless($policy->canOperateTenant($request->user(), $tenant), 403);
    }

    private function payload(Quotation $quotation): array
    {
        return ['id' => $quotation->id, 'number' => 'C-'.str_pad((string) $quotation->quotation_number, 6, '0', STR_PAD_LEFT), 'title' => $quotation->title, 'status' => $quotation->status, 'customer' => ['id' => $quotation->core_customer_id, 'name' => $quotation->customer_name, 'phone' => $quotation->customer_phone, 'email' => $quotation->customer_email], 'subtotal' => (float) $quotation->subtotal, 'discount_total' => (float) $quotation->discount_total, 'total' => (float) $quotation->total, 'requested_deposit' => (float) $quotation->requested_deposit, 'valid_until' => $quotation->valid_until?->toDateString(), 'terms' => $quotation->terms, 'notes' => $quotation->notes, 'order_id' => $quotation->order?->id, 'lines' => $quotation->lines->map(fn ($line) => ['id' => $line->id, 'description' => $line->description_snapshot, 'quantity' => (float) $line->quantity, 'unit_price' => (float) $line->unit_price, 'discount_amount' => (float) $line->discount_amount, 'total' => (float) $line->line_total])->values(), 'created_at' => $quotation->created_at?->toISOString()];
    }
}

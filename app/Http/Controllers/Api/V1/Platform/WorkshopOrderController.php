<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\WorkshopCustomer;
use App\Models\WorkshopDevice;
use App\Models\WorkshopOrder;
use App\Services\PlatformAccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WorkshopOrderController extends Controller
{
    public function index(Request $request, Tenant $tenant, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canViewTenantCatalog($request->user(), $tenant), 403);
        $query = $tenant->workshopOrders()->with(['device.customer', 'payments'])->latest('received_at');
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('q')) {
            $term = trim((string) $request->query('q'));
            $query->where(function ($q) use ($term): void {
                $q->where('ticket_number', 'like', "%{$term}%")
                    ->orWhere('reported_fault', 'like', "%{$term}%")
                    ->orWhereHas('device', fn ($d) => $d->where('brand', 'like', "%{$term}%")->orWhere('model', 'like', "%{$term}%")->orWhere('imei', 'like', "%{$term}%")->orWhere('serial_number', 'like', "%{$term}%"))
                    ->orWhereHas('device.customer', fn ($c) => $c->where('name', 'like', "%{$term}%"));
            });
        }

        return response()->json(['data' => $query->limit(100)->get()->map(fn (WorkshopOrder $order) => $this->payload($order))->values()]);
    }

    public function store(Request $request, Tenant $tenant, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canViewTenantCatalog($request->user(), $tenant), 403);
        $data = $request->validate([
            'customer.core_customer_id' => ['required', 'integer', 'min:1'],
            'customer.name' => ['required', 'string', 'max:160'],
            'customer.phone' => ['nullable', 'string', 'max:40'],
            'customer.email' => ['nullable', 'email', 'max:160'],
            'device.type' => ['required', Rule::in(['phone', 'tablet', 'laptop', 'desktop', 'console', 'controller', 'instrument', 'tv', 'audio', 'other'])],
            'device.brand' => ['required', 'string', 'max:80'],
            'device.model' => ['required', 'string', 'max:120'],
            'device.color' => ['nullable', 'string', 'max:60'],
            'device.imei' => ['nullable', 'digits:15'],
            'device.serial_number' => ['nullable', 'string', 'max:120'],
            'reported_fault' => ['required', 'string', 'max:5000'],
            'physical_condition' => ['nullable', 'string', 'max:5000'],
            'accessories' => ['nullable', 'array', 'max:30'],
            'accessories.*' => ['string', 'max:100'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'advance.amount' => ['nullable', 'numeric', 'min:0.01', 'max:999999999.99'],
            'advance.method' => ['required_with:advance.amount', Rule::in(['cash', 'card', 'transfer', 'other'])],
            'advance.reference' => ['nullable', 'string', 'max:120'],
        ]);

        $order = DB::transaction(function () use ($data, $request, $tenant): WorkshopOrder {
            $customer = WorkshopCustomer::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'core_customer_id' => $data['customer']['core_customer_id']],
                ['name' => trim($data['customer']['name']), 'phone' => $data['customer']['phone'] ?? null, 'email' => $data['customer']['email'] ?? null],
            );
            $device = WorkshopDevice::query()->create([
                'tenant_id' => $tenant->id, 'workshop_customer_id' => $customer->id,
                ...$data['device'],
            ]);
            $ticket = ((int) WorkshopOrder::query()->where('tenant_id', $tenant->id)->lockForUpdate()->max('ticket_number')) + 1;
            $order = WorkshopOrder::query()->create([
                'tenant_id' => $tenant->id, 'workshop_device_id' => $device->id,
                'received_by' => $request->user()->id, 'ticket_number' => $ticket,
                'status' => 'received', 'priority' => $data['priority'] ?? 'normal',
                'reported_fault' => $data['reported_fault'], 'physical_condition' => $data['physical_condition'] ?? null,
                'accessories' => $data['accessories'] ?? [], 'received_at' => now(),
            ]);
            if (($data['advance']['amount'] ?? null) !== null) {
                $order->payments()->create([
                    'tenant_id' => $tenant->id, 'received_by' => $request->user()->id,
                    'amount' => $data['advance']['amount'], 'method' => $data['advance']['method'],
                    'reference' => $data['advance']['reference'] ?? null, 'received_at' => now(),
                ]);
            }

            return $order;
        });

        return response()->json(['data' => $this->payload($order->load(['device.customer', 'payments']))], 201);
    }

    public function update(Request $request, Tenant $tenant, WorkshopOrder $order, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($order->tenant_id === $tenant->id, 404);
        abort_unless($policy->canViewTenantCatalog($request->user(), $tenant), 403);
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['received', 'diagnosing', 'awaiting_approval', 'approved', 'repairing', 'ready', 'delivered', 'cancelled'])],
            'diagnosis' => ['nullable', 'string', 'max:10000'],
            'estimated_total' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
        ]);
        $order->fill($data);
        if (($data['status'] ?? null) === 'ready') {
            $order->completed_at = now();
        }
        if (($data['status'] ?? null) === 'delivered') {
            $order->delivered_at = now();
        }
        $order->save();

        return response()->json(['data' => $this->payload($order->refresh()->load(['device.customer', 'payments']))]);
    }

    private function payload(WorkshopOrder $order): array
    {
        $paid = (float) $order->payments->whereNull('voided_at')->sum('amount');

        return [
            'id' => $order->id, 'ticket' => 'T-'.str_pad((string) $order->ticket_number, 6, '0', STR_PAD_LEFT),
            'status' => $order->status, 'priority' => $order->priority, 'reported_fault' => $order->reported_fault,
            'physical_condition' => $order->physical_condition, 'accessories' => $order->accessories ?? [],
            'diagnosis' => $order->diagnosis, 'estimated_total' => $order->estimated_total !== null ? (float) $order->estimated_total : null,
            'paid_total' => $paid, 'balance' => max(0, (float) ($order->estimated_total ?? 0) - $paid),
            'received_at' => $order->received_at?->toISOString(),
            'customer' => ['id' => $order->device->customer->core_customer_id, 'name' => $order->device->customer->name, 'phone' => $order->device->customer->phone],
            'device' => ['id' => $order->device->id, 'type' => $order->device->type, 'brand' => $order->device->brand, 'model' => $order->device->model, 'color' => $order->device->color, 'imei' => $order->device->imei, 'serial_number' => $order->device->serial_number],
        ];
    }
}

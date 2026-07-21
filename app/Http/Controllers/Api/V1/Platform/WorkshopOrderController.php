<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\InventorySale;
use App\Models\Tenant;
use App\Models\WorkshopCustomer;
use App\Models\WorkshopDevice;
use App\Models\WorkshopOrder;
use App\Models\WorkshopOrderPhoto;
use App\Models\WorkshopPhotoSession;
use App\Models\WorkshopReception;
use App\Services\Cash\CashService;
use App\Services\Inventory\CommercialSummaryService;
use App\Services\Inventory\InventoryService;
use App\Services\PlatformAccessPolicy;
use App\Services\WorkshopDeviceAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkshopOrderController extends Controller
{
    public function dashboard(Request $request, Tenant $tenant, PlatformAccessPolicy $policy, CommercialSummaryService $commercial): JsonResponse
    {
        abort_unless($policy->canViewTenantCatalog($request->user(), $tenant), 403);

        $activeStatuses = ['received', 'diagnosing', 'awaiting_approval', 'approved', 'repairing', 'ready'];
        $orders = $tenant->workshopOrders();
        $receivables = (clone $orders)
            ->whereNotNull('closed_at')
            ->where('financial_status', 'pending')
            ->with('payments')
            ->get()
            ->sum(fn (WorkshopOrder $order): float => max(0, round((float) $order->final_total - $this->netPaid($order), 2)));
        $dteReceivables = InventorySale::query()
            ->where('tenant_id', $tenant->id)
            ->where('source_type', 'dte')
            ->where('status', 'active')
            ->get(['total_amount', 'reporting_sign', 'metadata'])
            ->filter(fn (InventorySale $sale): bool => data_get($sale->metadata, 'payment_status') === 'receivable')
            ->sum(fn (InventorySale $sale): float => (float) $sale->total_amount * (int) $sale->reporting_sign);

        $recent = (clone $orders)
            ->whereIn('status', $activeStatuses)
            ->with(['device.customer', 'payments'])
            ->withCount('photos')
            ->latest('received_at')
            ->limit(6)
            ->get();

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'orders' => [
                'active' => (clone $orders)->whereIn('status', $activeStatuses)->count(),
                'received_today' => (clone $orders)->whereDate('received_at', today())->count(),
                'awaiting_approval' => (clone $orders)->where('status', 'awaiting_approval')->count(),
                'ready' => (clone $orders)->where('status', 'ready')->count(),
                'urgent' => (clone $orders)->whereIn('status', $activeStatuses)->where('priority', 'urgent')->count(),
            ],
            'commercial' => [
                ...$commercial->totals($tenant),
                'receivables' => round((float) $receivables + (float) $dteReceivables, 2),
            ],
            'recent_orders' => $recent->map(fn (WorkshopOrder $order): array => $this->payload($order))->values(),
        ]);
    }

    public function index(Request $request, Tenant $tenant, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canViewTenantCatalog($request->user(), $tenant), 403);
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['received', 'diagnosing', 'awaiting_approval', 'approved', 'repairing', 'ready', 'delivered', 'cancelled'])],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'payment_status' => ['nullable', Rule::in(['receivable'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        if (! empty($data['date_from']) && ! empty($data['date_to']) && $data['date_to'] < $data['date_from']) {
            throw ValidationException::withMessages(['date_to' => 'La fecha final debe ser igual o posterior a la fecha inicial.']);
        }
        $query = $tenant->workshopOrders()->with(['device.customer', 'payments', 'reception'])->withCount('photos');
        if ($request->filled('q')) {
            $term = trim((string) $data['q']);
            $query->where(function ($q) use ($term): void {
                $q->where('ticket_number', 'like', "%{$term}%")
                    ->orWhere('reported_fault', 'like', "%{$term}%")
                    ->orWhereHas('device', fn ($d) => $d->where('brand', 'like', "%{$term}%")->orWhere('model', 'like', "%{$term}%")->orWhere('imei', 'like', "%{$term}%")->orWhere('serial_number', 'like', "%{$term}%"))
                    ->orWhereHas('device.customer', fn ($c) => $c->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"));
            });
        }
        if (! empty($data['priority'])) {
            $query->where('priority', $data['priority']);
        }
        if (! empty($data['date_from'])) {
            $query->whereDate('received_at', '>=', $data['date_from']);
        }
        if (! empty($data['date_to'])) {
            $query->whereDate('received_at', '<=', $data['date_to']);
        }
        if (($data['payment_status'] ?? null) === 'receivable') {
            $query->whereNotNull('closed_at')->where('financial_status', 'pending');
        }
        $statsQuery = clone $query;
        $stats = collect(['received', 'diagnosing', 'awaiting_approval', 'approved', 'repairing', 'ready', 'delivered', 'cancelled'])
            ->mapWithKeys(fn (string $status): array => [$status => (clone $statsQuery)->where('status', $status)->count()]);
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        $orders = $query->latest('received_at')->paginate((int) ($data['per_page'] ?? 15));

        return response()->json([
            'data' => collect($orders->items())->map(fn (WorkshopOrder $order) => $this->payload($order))->values(),
            'meta' => ['current_page' => $orders->currentPage(), 'last_page' => $orders->lastPage(), 'per_page' => $orders->perPage(), 'total' => $orders->total()],
            'stats' => $stats,
        ]);
    }

    public function store(Request $request, Tenant $tenant, PlatformAccessPolicy $policy, WorkshopDeviceAccessService $deviceAccess, CashService $cash): JsonResponse
    {
        abort_unless($policy->canOperateTenant($request->user(), $tenant), 403);
        $data = $request->validate([
            'reception_id' => ['nullable', Rule::exists('workshop_receptions', 'id')->where('tenant_id', $tenant->id)],
            'customer.core_customer_id' => ['required', 'integer', 'min:1'],
            'customer.name' => ['required', 'string', 'max:160'],
            'customer.phone' => ['nullable', 'string', 'max:40'],
            'customer.email' => ['nullable', 'email', 'max:160'],
            'core_sucursal_id' => ['nullable', 'integer', 'min:1'],
            'core_sucursal_code' => ['nullable', 'string', 'max:30'],
            'core_sucursal_name' => ['nullable', 'string', 'max:160'],
            'device.type' => ['required', Rule::in(['phone', 'tablet', 'laptop', 'desktop', 'console', 'controller', 'instrument', 'tv', 'audio', 'other'])],
            'device.brand' => ['required', 'string', 'max:80'],
            'device.model' => ['required', 'string', 'max:120'],
            'device.color' => ['nullable', 'string', 'max:60'],
            'device.imei' => ['nullable', 'digits:15'],
            'device.serial_number' => ['nullable', 'string', 'max:120'],
            'device.identifier_not_visible' => ['nullable', 'boolean'],
            'device.power_status' => ['required', Rule::in(['on', 'off', 'not_tested'])],
            'device.functional_tests' => ['nullable', 'array'],
            'device.functional_tests.*' => [Rule::in(['passed', 'failed', 'not_tested'])],
            'device.is_locked' => ['nullable', 'boolean'],
            'device.access_type' => ['nullable', Rule::in(['code', 'pattern'])],
            'device.access_secret' => ['nullable', 'string', 'max:100'],
            'reported_fault' => ['required', 'string', 'max:5000'],
            'physical_condition' => ['nullable', 'string', 'max:5000'],
            'physical_conditions' => ['nullable', 'array', 'max:20'],
            'physical_conditions.*' => [Rule::in(['scratches', 'dents', 'cracked', 'missing_parts', 'moisture', 'opened', 'tampered_screws', 'no_accessories'])],
            'accessories' => ['nullable', 'array', 'max:30'],
            'accessories.*' => ['string', 'max:100'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'estimated_total' => ['nullable', 'required_with:advance.amount', 'numeric', 'min:0', 'max:999999999.99'],
            'advance.amount' => ['nullable', 'numeric', 'min:0', 'max:999999999.99', 'lte:estimated_total'],
            'advance.method' => ['required_with:advance.amount', Rule::in(['cash', 'card', 'transfer', 'other'])],
            'advance.reference' => ['nullable', 'string', 'max:120'],
        ]);

        $this->validateReceptionRules($data);
        if (($data['device']['power_status'] ?? null) !== 'on') {
            $data['device']['functional_tests'] = [];
        }
        if (! ($data['device']['is_locked'] ?? false)) {
            $data['device']['access_type'] = null;
            $data['device']['access_secret'] = null;
        }

        $order = DB::transaction(function () use ($data, $request, $tenant, $cash): WorkshopOrder {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            if (! empty($data['reception_id'])) {
                $reception = WorkshopReception::query()->where('tenant_id', $tenant->id)->with('customer')->lockForUpdate()->findOrFail($data['reception_id']);
                if ((int) $reception->customer->core_customer_id !== (int) $data['customer']['core_customer_id']) {
                    throw ValidationException::withMessages(['customer' => 'El equipo adicional debe pertenecer al cliente de la recepción.']);
                }
                if ($reception->core_sucursal_id !== null && (int) ($data['core_sucursal_id'] ?? 0) !== (int) $reception->core_sucursal_id) {
                    throw ValidationException::withMessages(['core_sucursal_id' => 'Todos los equipos de la recepción deben ingresar en la misma sucursal.']);
                }
                $customer = $reception->customer;
                $customer->forceFill(['name' => trim($data['customer']['name']), 'phone' => $data['customer']['phone'] ?? null, 'email' => $data['customer']['email'] ?? null])->save();
            } else {
                $customer = WorkshopCustomer::query()->updateOrCreate(
                    ['tenant_id' => $tenant->id, 'core_customer_id' => $data['customer']['core_customer_id']],
                    ['name' => trim($data['customer']['name']), 'phone' => $data['customer']['phone'] ?? null, 'email' => $data['customer']['email'] ?? null],
                );
                $ticket = ((int) WorkshopReception::query()->where('tenant_id', $tenant->id)->max('ticket_number')) + 1;
                $reception = WorkshopReception::query()->create([
                    'tenant_id' => $tenant->id, 'workshop_customer_id' => $customer->id,
                    'core_sucursal_id' => $data['core_sucursal_id'] ?? null, 'core_sucursal_code' => $data['core_sucursal_code'] ?? null, 'core_sucursal_name' => $data['core_sucursal_name'] ?? null,
                    'received_by' => $request->user()->id, 'ticket_number' => $ticket, 'received_at' => now(),
                ]);
            }
            $device = WorkshopDevice::query()->create([
                'tenant_id' => $tenant->id, 'workshop_customer_id' => $customer->id,
                ...$data['device'],
            ]);
            $sequence = ((int) WorkshopOrder::query()->where('workshop_reception_id', $reception->id)->max('reception_sequence')) + 1;
            $order = WorkshopOrder::query()->create([
                'tenant_id' => $tenant->id, 'workshop_reception_id' => $reception->id, 'workshop_device_id' => $device->id,
                'core_sucursal_id' => $data['core_sucursal_id'] ?? null,
                'core_sucursal_code' => $data['core_sucursal_code'] ?? null,
                'core_sucursal_name' => $data['core_sucursal_name'] ?? null,
                'received_by' => $request->user()->id, 'ticket_number' => $reception->ticket_number, 'reception_sequence' => $sequence,
                'status' => 'received', 'priority' => $data['priority'] ?? 'normal',
                'reported_fault' => $data['reported_fault'], 'physical_condition' => $data['physical_condition'] ?? null,
                'physical_conditions' => $data['physical_conditions'] ?? [],
                'accessories' => $data['accessories'] ?? [], 'estimated_total' => $data['estimated_total'] ?? null, 'received_at' => now(),
            ]);
            if ((float) ($data['advance']['amount'] ?? 0) > 0) {
                $payment = $order->payments()->create([
                    'tenant_id' => $tenant->id, 'received_by' => $request->user()->id,
                    'amount' => $data['advance']['amount'], 'method' => $data['advance']['method'],
                    'reference' => $data['advance']['reference'] ?? null, 'received_at' => now(),
                ]);
                $cash->recordWorkshopPayment($tenant, $payment);
            }

            return $order;
        });

        return response()->json(['data' => [
            ...$this->payload($order->load(['device.customer', 'payments', 'reception'])),
            'device_access' => $deviceAccess->ensure($order),
        ]], 201);
    }

    public function deviceAccess(Request $request, Tenant $tenant, WorkshopOrder $order, PlatformAccessPolicy $policy, WorkshopDeviceAccessService $deviceAccess): JsonResponse
    {
        abort_unless($order->tenant_id === $tenant->id, 404);
        abort_unless($policy->canOperateTenant($request->user(), $tenant), 403);

        return response()->json(['data' => $deviceAccess->ensure($order)]);
    }

    public function show(Request $request, Tenant $tenant, WorkshopOrder $order, PlatformAccessPolicy $policy): JsonResponse
    {
        $this->authorizeOrder($request, $tenant, $order, $policy);

        return response()->json(['data' => $this->payload($order->load(['device.customer', 'payments', 'reception']))]);
    }

    public function showReception(Request $request, Tenant $tenant, WorkshopReception $reception, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($reception->tenant_id === $tenant->id, 404);
        abort_unless($policy->canViewTenantCatalog($request->user(), $tenant), 403);
        $orders = $reception->orders()->with(['device.customer', 'payments', 'reception'])->withCount('photos')->orderBy('reception_sequence')->get();

        return response()->json(['data' => [
            'id' => $reception->id,
            'ticket' => 'T-'.str_pad((string) $reception->ticket_number, 6, '0', STR_PAD_LEFT),
            'received_at' => $reception->received_at?->toISOString(),
            'orders' => $orders->map(fn (WorkshopOrder $order): array => $this->payload($order))->values(),
        ]]);
    }

    public function update(Request $request, Tenant $tenant, WorkshopOrder $order, PlatformAccessPolicy $policy, CashService $cash): JsonResponse
    {
        abort_unless($order->tenant_id === $tenant->id, 404);
        abort_unless($policy->canOperateTenant($request->user(), $tenant), 403);
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['received', 'diagnosing', 'awaiting_approval', 'approved', 'repairing', 'ready', 'delivered', 'cancelled'])],
            'diagnosis' => ['nullable', 'string', 'max:10000'],
            'estimated_total' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'approval_decision' => ['nullable', Rule::in(['approved', 'rejected'])],
            'approval_method' => ['nullable', Rule::in(['whatsapp', 'call', 'in_person'])],
            'approval_notes' => ['nullable', 'string', 'max:2000'],
            'payment.amount' => ['nullable', 'numeric', 'min:0.01', 'max:999999999.99'],
            'payment.method' => ['required_with:payment.amount', Rule::in(['cash', 'card', 'transfer', 'other'])],
            'payment.reference' => ['nullable', 'string', 'max:120'],
            'payment.notes' => ['nullable', 'string', 'max:2000'],
        ]);
        if (isset($data['payment']) && ($data['approval_decision'] ?? null) !== 'approved') {
            throw ValidationException::withMessages(['payment' => 'El abono al aprobar requiere que el cliente acepte el presupuesto.']);
        }
        $order = DB::transaction(function () use ($data, $request, $tenant, $order, $cash): WorkshopOrder {
            $locked = WorkshopOrder::query()->whereKey($order->id)->with('payments')->lockForUpdate()->firstOrFail();
            $nextStatus = $data['status'] ?? $locked->status;
            $this->validateTransition($locked, $nextStatus, $data);
            $update = collect($data)->except('payment')->all();
            if (array_key_exists('approval_decision', $update)) {
                $update['status'] = $update['approval_decision'] === 'approved' ? 'approved' : 'cancelled';
                $update['approval_recorded_by'] = $request->user()->id;
                $update['approval_decided_at'] = now();
            }
            $locked->fill($update);
            if (($update['status'] ?? null) === 'ready') {
                $locked->completed_at = now();
            }
            if (($update['status'] ?? null) === 'delivered') {
                $locked->delivered_at = now();
            }
            $locked->save();
            if (isset($data['payment'])) {
                $this->recordOpenOrderPayment($locked, $tenant, $request, $data['payment'], $cash, 'Abono al aprobar presupuesto');
            }

            return $locked->refresh()->load(['device.customer', 'payments', 'reception']);
        });

        return response()->json(['data' => $this->payload($order)]);
    }

    public function settle(Request $request, Tenant $tenant, WorkshopOrder $order, PlatformAccessPolicy $policy, InventoryService $inventory, CashService $cash): JsonResponse
    {
        $this->authorizeOrderOperation($request, $tenant, $order, $policy);
        $data = $request->validate([
            'action' => ['required', Rule::in(['deliver_close', 'cancel_close'])],
            'final_total' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'retained_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'diagnostic_charge' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'method' => ['nullable', Rule::in(['cash', 'card', 'transfer', 'other'])],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'document_choice' => ['nullable', Rule::in(['work_order', 'dte'])],
            'dte_type' => ['nullable', Rule::in(['01', '03'])],
            'payment_timing' => ['nullable', Rule::in(['paid_now', 'credit'])],
            'amount_received' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
        ]);
        if (($data['action'] ?? null) === 'cancel_close' && blank($data['notes'] ?? null)) {
            throw ValidationException::withMessages(['notes' => 'Indica por qué se canceló la orden.']);
        }

        $order = DB::transaction(function () use ($data, $request, $tenant, $order, $inventory, $cash): WorkshopOrder {
            $locked = WorkshopOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->closed_at !== null, 422, 'La orden ya está cerrada.');
            $paid = $this->netPaid($locked);

            if ($data['action'] === 'deliver_close') {
                abort_unless($locked->status === 'ready', 422, 'Solo una orden lista puede entregarse y cerrarse.');
                $total = (float) ($data['final_total'] ?? $locked->estimated_total ?? 0);
                if ($paid > $total) {
                    throw ValidationException::withMessages(['final_total' => 'El total final no puede ser menor que lo ya recibido.']);
                }
                $due = round($total - $paid, 2);
                $amountReceived = array_key_exists('amount_received', $data)
                    ? round((float) $data['amount_received'], 2)
                    : (($data['payment_timing'] ?? 'paid_now') === 'paid_now' ? $due : 0.0);
                if ($amountReceived > $due) {
                    throw ValidationException::withMessages(['amount_received' => 'El monto recibido no puede superar el saldo pendiente.']);
                }
                if ($amountReceived > 0 && empty($data['method'])) {
                    throw ValidationException::withMessages(['method' => 'Selecciona cómo se recibió el saldo final.']);
                }
                if ($amountReceived > 0) {
                    $payment = $locked->payments()->create(['tenant_id' => $tenant->id, 'received_by' => $request->user()->id, 'kind' => 'payment', 'amount' => $amountReceived, 'method' => $data['method'], 'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? 'Cobro al entregar y cerrar', 'received_at' => now()]);
                    $cash->recordWorkshopPayment($tenant, $payment);
                }
                $remaining = round($due - $amountReceived, 2);
                $wantsDte = ($data['document_choice'] ?? 'work_order') === 'dte';
                $locked->forceFill(['status' => 'delivered', 'final_total' => $total, 'financial_status' => $remaining > 0 ? 'pending' : 'settled', 'billing_status' => $wantsDte ? 'pending' : 'unbilled', 'dte_type' => $wantsDte ? ($data['dte_type'] ?? '01') : null, 'delivered_at' => now(), 'closed_at' => now(), 'closed_by' => $request->user()->id])->save();
                $inventory->recordSale($tenant, [
                    'source_type' => 'workshop_order', 'source_id' => (string) $locked->id,
                    'core_sucursal_id' => $locked->core_sucursal_id, 'core_sucursal_code' => $locked->core_sucursal_code, 'core_sucursal_name' => $locked->core_sucursal_name,
                    'source_number' => 'T-'.str_pad((string) $locked->ticket_number, 6, '0', STR_PAD_LEFT),
                    'sale_date' => now()->toDateString(),
                    'metadata' => ['customer_id' => $locked->device->customer->core_customer_id, 'customer_name' => $locked->device->customer->name, 'payment_status' => $remaining > 0 ? 'receivable' : 'paid', 'outstanding_amount' => $remaining, 'billing_status' => $wantsDte ? 'pending' : 'unbilled'],
                    'lines' => [['description' => 'Servicio de reparación '.$locked->device->brand.' '.$locked->device->model, 'quantity' => 1, 'unit_price' => $total, 'net_total' => $total, 'line_origin' => 'free']],
                ], $request->user()->id);
            } else {
                abort_unless($locked->status === 'cancelled', 422, 'Solo una orden cancelada puede liquidarse como cancelación.');
                $diagnosticCharge = round((float) ($data['diagnostic_charge'] ?? $data['retained_amount'] ?? 0), 2);
                $refund = max(0, round($paid - $diagnosticCharge, 2));
                $collection = max(0, round($diagnosticCharge - $paid, 2));
                if (($refund > 0 || $collection > 0) && empty($data['method'])) {
                    throw ValidationException::withMessages(['method' => $refund > 0 ? 'Selecciona cómo se devolvió el anticipo.' : 'Selecciona cómo se recibió el cobro de diagnóstico.']);
                }
                if ($refund > 0) {
                    $payment = $locked->payments()->create(['tenant_id' => $tenant->id, 'received_by' => $request->user()->id, 'kind' => 'refund', 'amount' => $refund, 'method' => $data['method'], 'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? 'Devolución por cancelación', 'received_at' => now()]);
                    $cash->recordWorkshopPayment($tenant, $payment);
                }
                if ($collection > 0) {
                    $payment = $locked->payments()->create(['tenant_id' => $tenant->id, 'received_by' => $request->user()->id, 'kind' => 'payment', 'amount' => $collection, 'method' => $data['method'], 'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? 'Cobro de diagnóstico al cancelar', 'received_at' => now()]);
                    $cash->recordWorkshopPayment($tenant, $payment);
                }
                $locked->forceFill(['final_total' => $diagnosticCharge, 'financial_status' => 'settled', 'closed_at' => now(), 'closed_by' => $request->user()->id])->save();
                if ($diagnosticCharge > 0) {
                    $inventory->recordSale($tenant, [
                        'source_type' => 'workshop_order', 'source_id' => (string) $locked->id,
                        'core_sucursal_id' => $locked->core_sucursal_id, 'core_sucursal_code' => $locked->core_sucursal_code, 'core_sucursal_name' => $locked->core_sucursal_name,
                        'source_number' => 'T-'.str_pad((string) $locked->ticket_number, 6, '0', STR_PAD_LEFT),
                        'sale_date' => now()->toDateString(),
                        'metadata' => ['customer_id' => $locked->device->customer->core_customer_id, 'customer_name' => $locked->device->customer->name, 'payment_status' => 'paid', 'outstanding_amount' => 0, 'billing_status' => 'unbilled', 'cancelled_repair' => true],
                        'lines' => [['description' => 'Servicio de diagnóstico '.$locked->device->brand.' '.$locked->device->model, 'quantity' => 1, 'unit_price' => $diagnosticCharge, 'net_total' => $diagnosticCharge, 'line_origin' => 'free']],
                    ], $request->user()->id);
                }
            }

            return $locked->load(['device.customer', 'payments']);
        });

        return response()->json(['data' => $this->payload($order)]);
    }

    public function linkInvoice(Request $request, Tenant $tenant, WorkshopOrder $order, PlatformAccessPolicy $policy): JsonResponse
    {
        $this->authorizeOrderOperation($request, $tenant, $order, $policy);
        abort_unless($order->closed_at !== null, 422, 'La orden debe estar cerrada antes de vincular un DTE.');
        $data = $request->validate([
            'core_dte_document_id' => ['required', 'integer', 'min:1'],
            'dte_number' => ['required', 'string', 'max:80'],
            'dte_generation_code' => ['required', 'string', 'max:80'],
            'dte_type' => ['required', Rule::in(['01', '03'])],
        ]);
        if ($order->core_dte_document_id && $order->core_dte_document_id !== $data['core_dte_document_id']) {
            throw ValidationException::withMessages(['core_dte_document_id' => 'La orden ya está vinculada a otro DTE.']);
        }
        $order->forceFill([...$data, 'billing_status' => 'invoiced', 'invoiced_at' => now()])->save();

        return response()->json(['data' => $this->payload($order->refresh()->load(['device.customer', 'payments']))]);
    }

    public function recordPayment(Request $request, Tenant $tenant, WorkshopOrder $order, PlatformAccessPolicy $policy, InventoryService $inventory, CashService $cash): JsonResponse
    {
        $this->authorizeOrderOperation($request, $tenant, $order, $policy);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999.99'],
            'method' => ['required', Rule::in(['cash', 'card', 'transfer', 'other'])],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $order = DB::transaction(function () use ($data, $request, $tenant, $order, $inventory, $cash): WorkshopOrder {
            $locked = WorkshopOrder::query()->whereKey($order->id)->with('payments')->lockForUpdate()->firstOrFail();
            abort_if(in_array($locked->status, ['cancelled'], true), 422, 'No se pueden agregar pagos a una orden cancelada.');
            if ($locked->closed_at !== null) {
                abort_unless($locked->financial_status === 'pending', 422, 'La orden no tiene saldo pendiente.');
            } else {
                abort_if($locked->estimated_total === null, 422, 'Registra un monto estimado antes de recibir un anticipo.');
            }
            $charge = (float) ($locked->final_total ?? $locked->estimated_total ?? 0);
            $balance = round($charge - $this->netPaid($locked), 2);
            if ((float) $data['amount'] > $balance) {
                throw ValidationException::withMessages(['amount' => 'El pago no puede superar el saldo pendiente.']);
            }
            $payment = $locked->payments()->create(['tenant_id' => $tenant->id, 'received_by' => $request->user()->id, 'kind' => 'payment', 'amount' => $data['amount'], 'method' => $data['method'], 'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? ($locked->closed_at ? 'Abono posterior al cierre' : 'Anticipo durante la orden'), 'received_at' => now()]);
            $cash->recordWorkshopPayment($tenant, $payment);
            $remaining = round($balance - (float) $data['amount'], 2);
            if ($locked->closed_at !== null && $remaining <= 0) {
                $locked->forceFill(['financial_status' => 'settled'])->save();
            }
            if ($locked->closed_at !== null) {
                $inventory->recordSale($tenant, ['source_type' => 'workshop_order', 'source_id' => (string) $locked->id, 'source_number' => 'T-'.str_pad((string) $locked->ticket_number, 6, '0', STR_PAD_LEFT), 'core_sucursal_id' => $locked->core_sucursal_id, 'core_sucursal_code' => $locked->core_sucursal_code, 'core_sucursal_name' => $locked->core_sucursal_name, 'metadata' => ['payment_status' => $remaining <= 0 ? 'paid' : 'receivable', 'outstanding_amount' => max(0, $remaining)], 'lines' => []], $request->user()->id);
            }

            return $locked->refresh()->load(['device.customer', 'payments']);
        });

        return response()->json(['data' => $this->payload($order)]);
    }

    public function photoSession(Request $request, Tenant $tenant, WorkshopOrder $order, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($order->tenant_id === $tenant->id, 404);
        abort_unless($policy->canOperateTenant($request->user(), $tenant), 403);
        WorkshopPhotoSession::query()->where('workshop_order_id', $order->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $token = Str::random(64);
        $session = WorkshopPhotoSession::query()->create([
            'tenant_id' => $tenant->id,
            'workshop_order_id' => $order->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(30),
        ]);
        $url = 'https://'.config('platform.hosts.taller').'/fotos/'.$token;

        return response()->json(['data' => ['url' => $url, 'expires_at' => $session->expires_at->toISOString()]], 201);
    }

    public function photos(Request $request, Tenant $tenant, WorkshopOrder $order, PlatformAccessPolicy $policy): JsonResponse
    {
        $this->authorizeOrder($request, $tenant, $order, $policy);
        $photos = $order->photos()->latest()->get()->map(fn (WorkshopOrderPhoto $photo): array => [
            'id' => $photo->id,
            'url' => url("/api/v1/platform/tenants/{$tenant->id}/workshop/orders/{$order->id}/photos/{$photo->id}"),
            'stage' => $photo->stage,
            'original_name' => $photo->original_name,
            'mime_type' => $photo->mime_type,
            'size' => $photo->size,
            'created_at' => $photo->created_at?->toISOString(),
        ]);

        return response()->json(['data' => $photos->values()]);
    }

    public function photo(Request $request, Tenant $tenant, WorkshopOrder $order, WorkshopOrderPhoto $photo, PlatformAccessPolicy $policy): StreamedResponse
    {
        $this->authorizeOrder($request, $tenant, $order, $policy);
        abort_unless($photo->tenant_id === $tenant->id && $photo->workshop_order_id === $order->id, 404);
        abort_unless(Storage::disk($photo->disk)->exists($photo->path), 404);

        return Storage::disk($photo->disk)->response($photo->path, $photo->original_name, [
            'Content-Type' => $photo->mime_type,
            'Cache-Control' => 'private, max-age=300',
            'Content-Disposition' => 'inline; filename="photo-'.$photo->id.'.jpg"',
        ]);
    }

    private function authorizeOrder(Request $request, Tenant $tenant, WorkshopOrder $order, PlatformAccessPolicy $policy): void
    {
        abort_unless($order->tenant_id === $tenant->id, 404);
        abort_unless($policy->canViewTenantCatalog($request->user(), $tenant), 403);
    }

    private function authorizeOrderOperation(Request $request, Tenant $tenant, WorkshopOrder $order, PlatformAccessPolicy $policy): void
    {
        abort_unless($order->tenant_id === $tenant->id, 404);
        abort_unless($policy->canOperateTenant($request->user(), $tenant), 403);
    }

    private function payload(WorkshopOrder $order): array
    {
        $paid = $this->netPaid($order);
        $refunded = (float) $order->payments->whereNull('voided_at')->where('kind', 'refund')->sum('amount');
        $charge = (float) ($order->final_total ?? $order->estimated_total ?? 0);

        return [
            'id' => $order->id, 'ticket' => 'T-'.str_pad((string) $order->ticket_number, 6, '0', STR_PAD_LEFT),
            'reception' => ['id' => $order->workshop_reception_id, 'sequence' => (int) $order->reception_sequence, 'equipment_label' => 'Equipo '.((int) $order->reception_sequence), 'equipment_count' => $order->reception?->orders()->count() ?? 1],
            'status' => $order->status, 'priority' => $order->priority, 'reported_fault' => $order->reported_fault,
            'physical_condition' => $order->physical_condition, 'physical_conditions' => $order->physical_conditions ?? [], 'accessories' => $order->accessories ?? [],
            'diagnosis' => $order->diagnosis, 'estimated_total' => $order->estimated_total !== null ? (float) $order->estimated_total : null,
            'approval' => ['decision' => $order->approval_decision, 'method' => $order->approval_method, 'notes' => $order->approval_notes, 'decided_at' => $order->approval_decided_at?->toISOString()],
            'paid_total' => $paid, 'refunded_total' => $refunded, 'balance' => max(0, $charge - $paid),
            'financial' => ['status' => $order->financial_status, 'final_total' => $order->final_total !== null ? (float) $order->final_total : null, 'closed_at' => $order->closed_at?->toISOString()],
            'billing' => ['status' => $order->billing_status, 'dte_type' => $order->dte_type, 'core_document_id' => $order->core_dte_document_id, 'number' => $order->dte_number, 'generation_code' => $order->dte_generation_code, 'invoiced_at' => $order->invoiced_at?->toISOString()],
            'received_at' => $order->received_at?->toISOString(),
            'branch' => $order->core_sucursal_id ? ['id' => $order->core_sucursal_id, 'code' => $order->core_sucursal_code, 'name' => $order->core_sucursal_name] : null,
            'photo_count' => isset($order->photos_count) ? (int) $order->photos_count : $order->photos()->count(),
            'customer' => ['id' => $order->device->customer->core_customer_id, 'name' => $order->device->customer->name, 'phone' => $order->device->customer->phone, 'email' => $order->device->customer->email],
            'device' => ['id' => $order->device->id, 'type' => $order->device->type, 'brand' => $order->device->brand, 'model' => $order->device->model, 'color' => $order->device->color, 'imei' => $order->device->imei, 'serial_number' => $order->device->serial_number, 'identifier_not_visible' => $order->device->identifier_not_visible, 'power_status' => $order->device->power_status, 'functional_tests' => $order->device->functional_tests ?? [], 'is_locked' => $order->device->is_locked, 'access_type' => $order->device->access_type, 'has_access_secret' => filled($order->device->access_secret)],
        ];
    }

    private function netPaid(WorkshopOrder $order): float
    {
        $payments = $order->relationLoaded('payments') ? $order->payments : $order->payments()->get();

        return round((float) $payments->whereNull('voided_at')->sum(fn ($payment) => $payment->kind === 'refund' ? -((float) $payment->amount) : (float) $payment->amount), 2);
    }

    /** @param array{amount:mixed,method:string,reference?:string|null,notes?:string|null} $data */
    private function recordOpenOrderPayment(WorkshopOrder $order, Tenant $tenant, Request $request, array $data, CashService $cash, string $defaultNotes): void
    {
        abort_if($order->closed_at !== null || $order->estimated_total === null, 422, 'La orden necesita un presupuesto abierto para registrar el anticipo.');
        $balance = round((float) $order->estimated_total - $this->netPaid($order), 2);
        if ((float) $data['amount'] > $balance) {
            throw ValidationException::withMessages(['payment.amount' => 'El abono no puede superar el saldo estimado.']);
        }
        $payment = $order->payments()->create([
            'tenant_id' => $tenant->id,
            'received_by' => $request->user()->id,
            'kind' => 'payment',
            'amount' => $data['amount'],
            'method' => $data['method'],
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? $defaultNotes,
            'received_at' => now(),
        ]);
        $cash->recordWorkshopPayment($tenant, $payment);
    }

    private function validateReceptionRules(array $data): void
    {
        $device = $data['device'];
        if (filled($device['imei'] ?? null) && ! $this->validImei((string) $device['imei'])) {
            throw ValidationException::withMessages(['device.imei' => 'El IMEI no tiene un dígito verificador válido.']);
        }
        if (($device['power_status'] ?? null) !== 'on' && ! empty($device['functional_tests'])) {
            throw ValidationException::withMessages(['device.functional_tests' => 'Las pruebas funcionales solo aplican cuando el equipo enciende.']);
        }
        $allowedTests = ['display', 'touch_controls', 'charging', 'cameras', 'audio', 'microphone', 'buttons', 'connectivity'];
        if (collect(array_keys($device['functional_tests'] ?? []))->contains(fn ($key) => ! in_array($key, $allowedTests, true))) {
            throw ValidationException::withMessages(['device.functional_tests' => 'La lista contiene una prueba funcional no permitida.']);
        }
        if (! ($device['is_locked'] ?? false)) {
            return;
        }
        $type = $device['access_type'] ?? null;
        $secret = (string) ($device['access_secret'] ?? '');
        if (! in_array($type, ['code', 'pattern'], true)) {
            throw ValidationException::withMessages(['device.access_type' => 'Selecciona el tipo de acceso.']);
        }
        if ($type === 'code' && ! preg_match('/^\d{4,12}$/', $secret)) {
            throw ValidationException::withMessages(['device.access_secret' => 'El código debe contener entre 4 y 12 dígitos.']);
        }
        if ($type === 'pattern') {
            $points = array_values(array_filter(explode('-', $secret)));
            if (count($points) < 4 || count($points) !== count(array_unique($points)) || collect($points)->contains(fn ($point) => ! preg_match('/^[1-9]$/', $point))) {
                throw ValidationException::withMessages(['device.access_secret' => 'El patrón debe contener al menos cuatro puntos sin repetir.']);
            }
        }
    }

    private function validateTransition(WorkshopOrder $order, string $nextStatus, array $data): void
    {
        $transitions = [
            'received' => ['received', 'diagnosing', 'cancelled'],
            'diagnosing' => ['diagnosing', 'awaiting_approval', 'cancelled'],
            'awaiting_approval' => ['awaiting_approval', 'approved', 'cancelled'],
            'approved' => ['approved', 'repairing', 'cancelled'],
            'repairing' => ['repairing', 'ready', 'cancelled'],
            'ready' => ['ready', 'delivered', 'cancelled'],
            'delivered' => ['delivered'],
            'cancelled' => ['cancelled'],
        ];
        if (! in_array($nextStatus, $transitions[$order->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => 'Ese cambio de estado no está permitido desde el estado actual.']);
        }
        if ($nextStatus === 'awaiting_approval') {
            $diagnosis = trim((string) ($data['diagnosis'] ?? $order->diagnosis));
            $estimate = $data['estimated_total'] ?? $order->estimated_total;
            if ($diagnosis === '') {
                throw ValidationException::withMessages(['diagnosis' => 'Registra el diagnóstico antes de solicitar aprobación.']);
            }
            if ($estimate === null) {
                throw ValidationException::withMessages(['estimated_total' => 'Registra el presupuesto antes de solicitar aprobación.']);
            }
        }
        if (array_key_exists('approval_decision', $data)) {
            if ($order->status !== 'awaiting_approval') {
                throw ValidationException::withMessages(['approval_decision' => 'La decisión solo puede registrarse mientras se espera aprobación.']);
            }
            if (empty($data['approval_method'])) {
                throw ValidationException::withMessages(['approval_method' => 'Selecciona cómo confirmó el cliente.']);
            }
        }
    }

    private function validImei(string $imei): bool
    {
        $sum = 0;
        foreach (str_split($imei) as $index => $digit) {
            $value = (int) $digit;
            if ($index % 2 === 1) {
                $value *= 2;
            }
            $sum += intdiv($value, 10) + ($value % 10);
        }

        return $sum % 10 === 0;
    }
}

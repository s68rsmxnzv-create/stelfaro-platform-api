<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\WorkshopCustomer;
use App\Models\WorkshopDevice;
use App\Models\WorkshopOrder;
use App\Models\WorkshopOrderPhoto;
use App\Models\WorkshopPhotoSession;
use App\Services\PlatformAccessPolicy;
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
    public function index(Request $request, Tenant $tenant, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canViewTenantCatalog($request->user(), $tenant), 403);
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['received', 'diagnosing', 'awaiting_approval', 'approved', 'repairing', 'ready', 'delivered', 'cancelled'])],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        if (! empty($data['date_from']) && ! empty($data['date_to']) && $data['date_to'] < $data['date_from']) {
            throw ValidationException::withMessages(['date_to' => 'La fecha final debe ser igual o posterior a la fecha inicial.']);
        }
        $query = $tenant->workshopOrders()->with(['device.customer', 'payments'])->withCount('photos');
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
            'advance.amount' => ['nullable', 'numeric', 'min:0.01', 'max:999999999.99', 'lte:estimated_total'],
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
                'physical_conditions' => $data['physical_conditions'] ?? [],
                'accessories' => $data['accessories'] ?? [], 'estimated_total' => $data['estimated_total'] ?? null, 'received_at' => now(),
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
            'approval_decision' => ['nullable', Rule::in(['approved', 'rejected'])],
            'approval_method' => ['nullable', Rule::in(['whatsapp', 'call', 'in_person'])],
            'approval_notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $nextStatus = $data['status'] ?? $order->status;
        $this->validateTransition($order, $nextStatus, $data);
        if (array_key_exists('approval_decision', $data)) {
            $data['status'] = $data['approval_decision'] === 'approved' ? 'approved' : 'cancelled';
            $data['approval_recorded_by'] = $request->user()->id;
            $data['approval_decided_at'] = now();
        }
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

    public function photoSession(Request $request, Tenant $tenant, WorkshopOrder $order, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($order->tenant_id === $tenant->id, 404);
        abort_unless($policy->canViewTenantCatalog($request->user(), $tenant), 403);
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

    private function payload(WorkshopOrder $order): array
    {
        $paid = (float) $order->payments->whereNull('voided_at')->sum('amount');

        return [
            'id' => $order->id, 'ticket' => 'T-'.str_pad((string) $order->ticket_number, 6, '0', STR_PAD_LEFT),
            'status' => $order->status, 'priority' => $order->priority, 'reported_fault' => $order->reported_fault,
            'physical_condition' => $order->physical_condition, 'physical_conditions' => $order->physical_conditions ?? [], 'accessories' => $order->accessories ?? [],
            'diagnosis' => $order->diagnosis, 'estimated_total' => $order->estimated_total !== null ? (float) $order->estimated_total : null,
            'approval' => ['decision' => $order->approval_decision, 'method' => $order->approval_method, 'notes' => $order->approval_notes, 'decided_at' => $order->approval_decided_at?->toISOString()],
            'paid_total' => $paid, 'balance' => max(0, (float) ($order->estimated_total ?? 0) - $paid),
            'received_at' => $order->received_at?->toISOString(),
            'photo_count' => isset($order->photos_count) ? (int) $order->photos_count : $order->photos()->count(),
            'customer' => ['id' => $order->device->customer->core_customer_id, 'name' => $order->device->customer->name, 'phone' => $order->device->customer->phone],
            'device' => ['id' => $order->device->id, 'type' => $order->device->type, 'brand' => $order->device->brand, 'model' => $order->device->model, 'color' => $order->device->color, 'imei' => $order->device->imei, 'serial_number' => $order->device->serial_number, 'identifier_not_visible' => $order->device->identifier_not_visible, 'power_status' => $order->device->power_status, 'functional_tests' => $order->device->functional_tests ?? [], 'is_locked' => $order->device->is_locked, 'access_type' => $order->device->access_type, 'has_access_secret' => filled($order->device->access_secret)],
        ];
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
            'ready' => ['ready', 'delivered'],
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

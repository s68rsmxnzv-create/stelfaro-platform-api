<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\InventoryReservation;
use App\Models\Tenant;
use App\Models\WorkshopOrder;
use App\Services\Inventory\InventoryService;
use App\Services\PlatformAccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class WorkshopMaterialController extends Controller
{
    public function index(Request $request, Tenant $tenant, WorkshopOrder $order, PlatformAccessPolicy $policy): JsonResponse
    {
        $this->authorizeOrder($request, $tenant, $order, $policy, false);

        return response()->json(['data' => $this->materials($tenant, $order)]);
    }

    public function store(Request $request, Tenant $tenant, WorkshopOrder $order, PlatformAccessPolicy $policy, InventoryService $inventory): JsonResponse
    {
        $this->authorizeOrder($request, $tenant, $order, $policy);
        $this->ensureOpen($order);

        $data = $request->validate([
            'catalog_item_id' => [
                'required',
                'integer',
                Rule::exists('catalog_items', 'id')->where(fn ($query) => $query
                    ->where('tenant_id', $tenant->id)
                    ->where('status', 'active')
                    ->where('controls_inventory', true)),
            ],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999999.999'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        if (! $order->core_sucursal_id) {
            throw ValidationException::withMessages(['branch' => 'La orden necesita una sucursal para reservar inventario.']);
        }

        try {
            $reservation = $inventory->reserve($tenant, [
                'idempotency_key' => 'workshop-material:'.$order->id.':'.str()->uuid(),
                'core_sucursal_id' => $order->core_sucursal_id,
                'core_sucursal_code' => $order->core_sucursal_code,
                'core_sucursal_name' => $order->core_sucursal_name,
                'source_type' => 'workshop_order',
                'source_id' => (string) $order->id,
                'source_number' => $this->ticket($order),
                'metadata' => ['purpose' => 'internal_workshop_material'],
                'lines' => [[
                    'catalog_item_id' => $data['catalog_item_id'],
                    'quantity' => $data['quantity'],
                    'description' => $data['description'] ?? null,
                ]],
            ], $request->user()?->id);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['quantity' => $exception->getMessage()]);
        }

        return response()->json(['data' => $this->material($reservation)], 201);
    }

    public function consume(Request $request, Tenant $tenant, WorkshopOrder $order, InventoryReservation $reservation, PlatformAccessPolicy $policy, InventoryService $inventory): JsonResponse
    {
        $this->authorizeMaterial($request, $tenant, $order, $reservation, $policy);
        $this->ensureOpen($order);

        try {
            $reservation = $inventory->confirmReservation($tenant, $reservation, [
                'source_type' => 'workshop_order',
                'source_id' => (string) $order->id,
                'source_number' => $this->ticket($order),
                'movement_reason' => 'workshop_consumption',
            ], $request->user()?->id);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['material' => $exception->getMessage()]);
        }

        return response()->json(['data' => $this->material($reservation)]);
    }

    public function release(Request $request, Tenant $tenant, WorkshopOrder $order, InventoryReservation $reservation, PlatformAccessPolicy $policy, InventoryService $inventory): JsonResponse
    {
        $this->authorizeMaterial($request, $tenant, $order, $reservation, $policy);
        $this->ensureOpen($order);

        try {
            $reservation = $inventory->releaseReservation($tenant, $reservation, $request->user()?->id);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['material' => $exception->getMessage()]);
        }

        return response()->json(['data' => $this->material($reservation)]);
    }

    public function returnToStock(Request $request, Tenant $tenant, WorkshopOrder $order, InventoryReservation $reservation, PlatformAccessPolicy $policy, InventoryService $inventory): JsonResponse
    {
        $this->authorizeMaterial($request, $tenant, $order, $reservation, $policy);
        $this->ensureOpen($order);
        $data = $request->validate(['notes' => ['required', 'string', 'max:2000']]);

        try {
            $reservation = $inventory->reverseConfirmedReservation($tenant, $reservation, [
                'source_type' => 'workshop_order',
                'source_id' => (string) $order->id,
                'source_number' => $this->ticket($order),
                'movement_reason' => 'workshop_return',
                'notes' => $data['notes'],
            ], $request->user()?->id);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['material' => $exception->getMessage()]);
        }

        return response()->json(['data' => $this->material($reservation)]);
    }

    private function authorizeOrder(Request $request, Tenant $tenant, WorkshopOrder $order, PlatformAccessPolicy $policy, bool $operate = true): void
    {
        abort_unless($order->tenant_id === $tenant->id, 404);
        abort_unless($operate ? $policy->canOperateTenant($request->user(), $tenant) : $policy->canViewTenantCatalog($request->user(), $tenant), 403);
    }

    private function authorizeMaterial(Request $request, Tenant $tenant, WorkshopOrder $order, InventoryReservation $reservation, PlatformAccessPolicy $policy): void
    {
        $this->authorizeOrder($request, $tenant, $order, $policy);
        abort_unless(
            $reservation->tenant_id === $tenant->id
            && $reservation->source_type === 'workshop_order'
            && $reservation->source_id === (string) $order->id,
            404
        );
    }

    private function ensureOpen(WorkshopOrder $order): void
    {
        abort_if($order->closed_at !== null || in_array($order->status, ['delivered', 'cancelled'], true), 422, 'La orden ya no admite movimientos de repuestos.');
    }

    private function materials(Tenant $tenant, WorkshopOrder $order): array
    {
        return InventoryReservation::query()
            ->where('tenant_id', $tenant->id)
            ->where('source_type', 'workshop_order')
            ->where('source_id', (string) $order->id)
            ->with(['lines.catalogItem', 'lines.allocations.lot'])
            ->latest('id')
            ->get()
            ->map(fn (InventoryReservation $reservation): array => $this->material($reservation))
            ->values()
            ->all();
    }

    private function material(InventoryReservation $reservation): array
    {
        $reservation->loadMissing(['lines.catalogItem', 'lines.allocations.lot']);
        $line = $reservation->lines->first();
        $cost = (float) ($line?->allocations->sum(fn ($allocation): float => (float) $allocation->quantity * (float) $allocation->unit_cost) ?? 0);
        $quantity = (float) ($line?->quantity ?? 0);

        return [
            'id' => $reservation->id,
            'status' => $reservation->status,
            'quantity' => $quantity,
            'unit_cost' => $quantity > 0 ? round($cost / $quantity, 4) : 0,
            'total_cost' => round($cost, 2),
            'item' => $line?->catalogItem ? [
                'id' => $line->catalogItem->id,
                'sku' => $line->catalogItem->sku,
                'name' => $line->catalogItem->name,
                'unit_name' => $line->catalogItem->unit_name,
            ] : null,
            'description' => $line?->description_snapshot,
            'branch' => $reservation->core_sucursal_id ? [
                'id' => $reservation->core_sucursal_id,
                'code' => $reservation->core_sucursal_code,
                'name' => $reservation->core_sucursal_name,
            ] : null,
            'reserved_at' => $reservation->created_at?->toISOString(),
            'consumed_at' => $reservation->confirmed_at?->toISOString(),
            'released_at' => $reservation->released_at?->toISOString(),
            'returned_at' => $reservation->reversed_at?->toISOString(),
        ];
    }

    private function ticket(WorkshopOrder $order): string
    {
        return 'T-'.str_pad((string) $order->ticket_number, 6, '0', STR_PAD_LEFT);
    }
}

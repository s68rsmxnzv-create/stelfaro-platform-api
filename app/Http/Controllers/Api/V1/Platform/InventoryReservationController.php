<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\InventoryReservation;
use App\Models\Tenant;
use App\Services\Inventory\InventoryService;
use App\Services\PlatformAccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class InventoryReservationController extends Controller
{
    public function store(Request $request, Tenant $tenant, PlatformAccessPolicy $policy, InventoryService $inventory): JsonResponse
    {
        abort_unless($policy->canOperateTenant($request->user(), $tenant), 403);

        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:120'],
            'core_sucursal_id' => ['nullable', 'integer', 'min:1'],
            'core_sucursal_code' => ['nullable', 'string', 'max:30'],
            'core_sucursal_name' => ['nullable', 'string', 'max:160'],
            'source_type' => ['nullable', 'string', 'max:40'],
            'source_id' => ['nullable', 'string', 'max:80'],
            'source_number' => ['nullable', 'string', 'max:120'],
            'metadata' => ['nullable', 'array'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.catalog_item_id' => ['required', 'integer', Rule::exists('catalog_items', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)->where('controls_inventory', true))],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $reservation = $inventory->reserve($tenant, $data, $request->user()?->id);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $reservation], $reservation->wasRecentlyCreated ? 201 : 200);
    }

    public function confirm(Request $request, Tenant $tenant, InventoryReservation $reservation, PlatformAccessPolicy $policy, InventoryService $inventory): JsonResponse
    {
        abort_unless($reservation->tenant_id === $tenant->id, 404);
        abort_unless($policy->canOperateTenant($request->user(), $tenant), 403);

        $data = $request->validate([
            'source_type' => ['nullable', 'string', 'max:40'],
            'source_id' => ['nullable', 'string', 'max:80'],
            'source_number' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $reservation = $inventory->confirmReservation($tenant, $reservation, $data, $request->user()?->id);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $reservation]);
    }

    public function release(Request $request, Tenant $tenant, InventoryReservation $reservation, PlatformAccessPolicy $policy, InventoryService $inventory): JsonResponse
    {
        abort_unless($reservation->tenant_id === $tenant->id, 404);
        abort_unless($policy->canOperateTenant($request->user(), $tenant), 403);

        try {
            $reservation = $inventory->releaseReservation($tenant, $reservation, $request->user()?->id);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $reservation]);
    }

    public function reverse(Request $request, Tenant $tenant, InventoryReservation $reservation, PlatformAccessPolicy $policy, InventoryService $inventory): JsonResponse
    {
        abort_unless($reservation->tenant_id === $tenant->id, 404);
        abort_unless($policy->canManageTenantCatalog($request->user(), $tenant), 403);

        $data = $request->validate([
            'source_type' => ['nullable', 'string', 'max:40'],
            'source_id' => ['nullable', 'string', 'max:80'],
            'source_number' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $reservation = $inventory->reverseConfirmedReservation($tenant, $reservation, $data, $request->user()?->id);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $reservation]);
    }
}

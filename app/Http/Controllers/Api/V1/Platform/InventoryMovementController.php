<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use App\Models\Tenant;
use App\Services\Inventory\InventoryService;
use App\Services\PlatformAccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class InventoryMovementController extends Controller
{
    public function index(Request $request, Tenant $tenant, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canViewTenantCatalog($request->user(), $tenant), 403);

        $query = InventoryMovement::query()
            ->where('tenant_id', $tenant->id)
            ->with('catalogItem:id,sku,name', 'lot:id,lot_code')
            ->orderByDesc('id');

        if ($request->filled('catalog_item_id')) {
            $query->where('catalog_item_id', (int) $request->query('catalog_item_id'));
        }
        if ($request->filled('core_sucursal_id')) {
            $query->where('core_sucursal_id', (int) $request->query('core_sucursal_id'));
        }
        if ($request->filled('movement_type')) {
            $query->where('movement_type', (string) $request->query('movement_type'));
        }
        if ($request->filled('reason')) {
            $query->where('reason', (string) $request->query('reason'));
        }

        return response()->json($query->paginate((int) min(max((int) $request->query('per_page', 50), 1), 100)));
    }

    public function adjust(Request $request, Tenant $tenant, PlatformAccessPolicy $policy, InventoryService $inventory): JsonResponse
    {
        abort_unless($policy->canManageTenantCatalog($request->user(), $tenant), 403);

        $data = $request->validate([
            'catalog_item_id' => ['required', 'integer', Rule::exists('catalog_items', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)->where('controls_inventory', true))],
            'core_sucursal_id' => ['nullable', 'integer', 'min:1'],
            'core_sucursal_code' => ['nullable', 'string', 'max:30'],
            'core_sucursal_name' => ['nullable', 'string', 'max:160'],
            'direction' => ['required', 'string', Rule::in(['entry', 'exit'])],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_cost' => ['nullable', 'numeric', 'gte:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $movement = $inventory->adjust($tenant, $data, $request->user()?->id);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $movement->load('catalogItem:id,sku,name', 'lot:id,lot_code')], 201);
    }
}

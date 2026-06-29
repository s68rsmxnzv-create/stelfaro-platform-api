<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\InventoryPurchase;
use App\Models\Tenant;
use App\Services\Inventory\InventoryService;
use App\Services\PlatformAccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class InventoryPurchaseController extends Controller
{
    public function index(Request $request, Tenant $tenant, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canViewTenantCatalog($request->user(), $tenant), 403);

        $query = $tenant->inventoryPurchases()
            ->with('supplier')
            ->orderByDesc('purchase_date')
            ->orderByDesc('id');

        return response()->json($query->paginate((int) min(max((int) $request->query('per_page', 25), 1), 100)));
    }

    public function show(Request $request, Tenant $tenant, InventoryPurchase $purchase, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($purchase->tenant_id === $tenant->id, 404);
        abort_unless($policy->canViewTenantCatalog($request->user(), $tenant), 403);

        return response()->json(['data' => $purchase->load('supplier', 'lines.catalogItem', 'lines.lots')]);
    }

    public function store(Request $request, Tenant $tenant, PlatformAccessPolicy $policy, InventoryService $inventory): JsonResponse
    {
        abort_unless($policy->canManageTenantCatalog($request->user(), $tenant), 403);

        $data = $request->validate([
            'inventory_supplier_id' => [
                'nullable',
                'integer',
                Rule::exists('inventory_suppliers', 'id')->where('tenant_id', $tenant->id),
            ],
            'document_type' => ['nullable', 'string', 'max:40'],
            'document_number' => ['nullable', 'string', 'max:80'],
            'purchase_date' => ['required', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.catalog_item_id' => [
                'required',
                'integer',
                Rule::exists('catalog_items', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)->where('controls_inventory', true)),
            ],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['required', 'numeric', 'gte:0'],
            'lines.*.tax_amount' => ['nullable', 'numeric', 'gte:0'],
        ]);

        try {
            $purchase = $inventory->registerPurchase($tenant, $data, $request->user()?->id);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $purchase], 201);
    }
}

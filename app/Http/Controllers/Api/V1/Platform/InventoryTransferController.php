<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Inventory\InventoryService;
use App\Services\PlatformAccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class InventoryTransferController extends Controller
{
    public function store(Request $request, Tenant $tenant, PlatformAccessPolicy $policy, InventoryService $inventory): JsonResponse
    {
        abort_unless($policy->canManageTenantCatalog($request->user(), $tenant), 403);

        $data = $request->validate([
            'from_core_sucursal_id' => ['required', 'integer', 'min:1'],
            'from_core_sucursal_code' => ['nullable', 'string', 'max:30'],
            'from_core_sucursal_name' => ['nullable', 'string', 'max:160'],
            'to_core_sucursal_id' => ['required', 'integer', 'min:1'],
            'to_core_sucursal_code' => ['nullable', 'string', 'max:30'],
            'to_core_sucursal_name' => ['nullable', 'string', 'max:160'],
            'transfer_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.catalog_item_id' => [
                'required',
                'integer',
                Rule::exists('catalog_items', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)->where('controls_inventory', true)),
            ],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        try {
            $transfer = $inventory->transfer($tenant, $data, $request->user()?->id);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $transfer], 201);
    }
}

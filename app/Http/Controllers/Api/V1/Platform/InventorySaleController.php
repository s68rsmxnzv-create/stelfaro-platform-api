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

class InventorySaleController extends Controller
{
    public function store(Request $request, Tenant $tenant, PlatformAccessPolicy $policy, InventoryService $inventory): JsonResponse
    {
        abort_unless($policy->canOperateTenant($request->user(), $tenant), 403);

        $data = $request->validate([
            'core_sucursal_id' => ['nullable', 'integer', 'min:1'],
            'core_sucursal_code' => ['nullable', 'string', 'max:30'],
            'core_sucursal_name' => ['nullable', 'string', 'max:160'],
            'source_type' => ['nullable', 'string', 'max:40'],
            'source_id' => ['required', 'string', 'max:80'],
            'source_number' => ['nullable', 'string', 'max:120'],
            'sale_date' => ['nullable', 'date'],
            'fiscal_document_type' => ['nullable', 'string', Rule::in(['01', '03', '05', '06', '14'])],
            'net_amount' => ['nullable', 'numeric', 'gte:0'],
            'tax_amount' => ['nullable', 'numeric', 'gte:0'],
            'total_amount' => ['nullable', 'numeric', 'gte:0'],
            'metadata' => ['nullable', 'array'],
            'replacement_of_source_type' => ['nullable', 'string', 'max:40'],
            'replacement_of_source_id' => ['nullable', 'string', 'max:80'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.catalog_item_id' => [
                'nullable',
                'integer',
                Rule::exists('catalog_items', 'id')->where('tenant_id', $tenant->id),
            ],
            'lines.*.line_origin' => ['nullable', 'string', Rule::in(['free', 'catalog', 'inventory'])],
            'lines.*.inherited_from_line_id' => ['nullable', 'integer'],
            'lines.*.inherited_quantity' => ['nullable', 'numeric', 'gte:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'gte:0'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'gte:0'],
            'lines.*.net_total' => ['nullable', 'numeric', 'gte:0'],
            'lines.*.tax_amount' => ['nullable', 'numeric', 'gte:0'],
            'lines.*.total_amount' => ['nullable', 'numeric', 'gte:0'],
            'lines.*.reference_unit_cost' => ['nullable', 'numeric', 'gte:0'],
        ]);

        try {
            $sale = $inventory->recordSale($tenant, $data, $request->user()?->id);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $sale], $sale->wasRecentlyCreated ? 201 : 200);
    }

    public function fulfillmentBySource(Request $request, Tenant $tenant, PlatformAccessPolicy $policy, InventoryService $inventory): JsonResponse
    {
        abort_unless($policy->canViewTenantCatalog($request->user(), $tenant), 403);

        $data = $request->validate([
            'source_type' => ['nullable', 'string', 'max:40'],
            'source_id' => ['required', 'string', 'max:80'],
        ]);

        try {
            $result = $inventory->saleFulfillment($tenant, $data['source_type'] ?? 'dte', $data['source_id']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $result]);
    }

    public function supersedeBySource(Request $request, Tenant $tenant, PlatformAccessPolicy $policy, InventoryService $inventory): JsonResponse
    {
        abort_unless($policy->canOperateTenant($request->user(), $tenant), 403);

        $data = $request->validate([
            'source_type' => ['nullable', 'string', 'max:40'],
            'original_source_id' => ['required', 'string', 'max:80'],
            'replacement_source_id' => ['required', 'string', 'max:80', 'different:original_source_id'],
            'event_id' => ['nullable', 'string', 'max:80'],
            'event_number' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $result = $inventory->supersedeDteSale($tenant, $data);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $result]);
    }

    public function reverseBySource(Request $request, Tenant $tenant, PlatformAccessPolicy $policy, InventoryService $inventory): JsonResponse
    {
        abort_unless($policy->canOperateTenant($request->user(), $tenant), 403);

        $data = $request->validate([
            'source_type' => ['nullable', 'string', 'max:40'],
            'source_id' => ['required', 'string', 'max:80'],
            'event_id' => ['nullable', 'string', 'max:80'],
            'event_number' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $result = $inventory->reverseDteSale($tenant, $data, $request->user()?->id);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $result]);
    }
}

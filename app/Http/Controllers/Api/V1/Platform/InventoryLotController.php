<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\InventoryLot;
use App\Models\Tenant;
use App\Services\PlatformAccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryLotController extends Controller
{
    public function index(Request $request, Tenant $tenant, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canViewTenantCatalog($request->user(), $tenant), 403);

        $query = InventoryLot::query()
            ->where('tenant_id', $tenant->id)
            ->with('catalogItem:id,sku,name,unit_code,unit_name')
            ->orderByRaw('COALESCE(received_date, DATE(created_at)) asc')
            ->orderBy('id');

        if ($request->filled('catalog_item_id')) {
            $query->where('catalog_item_id', (int) $request->query('catalog_item_id'));
        }
        if ($request->filled('core_sucursal_id')) {
            $query->where('core_sucursal_id', (int) $request->query('core_sucursal_id'));
        }
        if ($request->boolean('available_only')) {
            $query->where('available_quantity', '>', 0);
        }

        return response()->json($query->paginate((int) min(max((int) $request->query('per_page', 50), 1), 100)));
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\InventoryPurchase;
use App\Models\Tenant;
use App\Services\Inventory\InventoryPurchaseImportService;
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
            'document_type' => ['nullable', 'string', 'max:40', Rule::in(['ccf', 'fcf', 'fse', 'dte_ccf', 'dte_fcf', 'nota_envio', 'manual'])],
            'document_mode' => ['nullable', 'string', Rule::in(['manual', 'dte', 'physical'])],
            'document_number' => ['nullable', 'string', 'max:80'],
            'payment_condition' => ['nullable', 'string', Rule::in(['cash', 'credit', 'contado', 'credito'])],
            'document_total' => ['nullable', 'numeric', 'gte:0'],
            'is_consumable' => ['sometimes', 'boolean'],
            'apply_tax_perceived' => ['sometimes', 'boolean'],
            'tax_perceived_mode' => ['nullable', 'string', Rule::in(['auto', 'manual'])],
            'tax_perceived_rate' => ['nullable', 'numeric', 'gte:0', 'lte:100'],
            'apply_fuel_charges' => ['sometimes', 'boolean'],
            'fovial_per_unit' => ['nullable', 'numeric', 'gte:0'],
            'cotrans_per_unit' => ['nullable', 'numeric', 'gte:0'],
            'fiscal_profile' => ['nullable', 'string', Rule::in(['sales_expense', 'administrative_expense', 'financial_expense', 'import_cost', 'internal_cost', 'manufacturing_overhead', 'labor_cost'])],
            'fiscal_sector' => ['nullable', 'integer', Rule::in([1, 2, 3, 4])],
            'supplier_snapshot' => ['nullable', 'array'],
            'import_metadata' => ['nullable', 'array'],
            'purchase_date' => ['required', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.catalog_item_id' => [
                'required',
                'integer',
                Rule::exists('catalog_items', 'id')->where('tenant_id', $tenant->id),
            ],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.unit_code' => ['nullable', 'string', 'max:10'],
            'lines.*.unit_name' => ['nullable', 'string', 'max:60'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['required', 'numeric', 'gte:0'],
            'lines.*.price_includes_tax' => ['sometimes', 'boolean'],
            'lines.*.no_inventory' => ['sometimes', 'boolean'],
        ]);

        if (! empty($data['inventory_supplier_id']) && ! blank($data['document_type'] ?? null) && ! blank($data['document_number'] ?? null)) {
            $exists = InventoryPurchase::query()
                ->where('tenant_id', $tenant->id)
                ->where('inventory_supplier_id', (int) $data['inventory_supplier_id'])
                ->where('document_type', strtolower((string) $data['document_type']))
                ->where('document_number', trim((string) $data['document_number']))
                ->exists();
            if ($exists) {
                return response()->json(['message' => 'Documento fiscal duplicado para este proveedor.'], 422);
            }
        }

        try {
            $purchase = $inventory->registerPurchase($tenant, $data, $request->user()?->id);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $purchase], 201);
    }

    public function importDteJson(Request $request, Tenant $tenant, PlatformAccessPolicy $policy, InventoryPurchaseImportService $importer): JsonResponse
    {
        abort_unless($policy->canManageTenantCatalog($request->user(), $tenant), 403);

        $data = $request->validate([
            'payload' => ['required', 'array'],
        ]);

        try {
            $preview = $importer->preview($tenant, $data['payload']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $preview]);
    }
}

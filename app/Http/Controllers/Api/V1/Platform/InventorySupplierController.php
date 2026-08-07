<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\InventorySupplier;
use App\Models\Tenant;
use App\Services\PlatformAccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InventorySupplierController extends Controller
{
    public function index(Request $request, Tenant $tenant, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canViewTenantCatalog($request->user(), $tenant), 403);

        $query = $tenant->inventorySuppliers()->orderBy('name');
        if ($request->filled('q')) {
            $term = trim((string) $request->query('q'));
            $query->where(fn ($sub) => $sub->whereLike('name', "%{$term}%")->orWhereLike('tax_id', "%{$term}%"));
        }
        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        return response()->json([
            'data' => $query->paginate((int) min(max((int) $request->query('per_page', 50), 1), 100))->items(),
        ]);
    }

    public function store(Request $request, Tenant $tenant, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canManageTenantCatalog($request->user(), $tenant), 403);

        $data = $this->validated($request, $tenant);
        $supplier = $tenant->inventorySuppliers()->create($data);

        return response()->json(['data' => $supplier], 201);
    }

    public function update(Request $request, Tenant $tenant, InventorySupplier $supplier, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($supplier->tenant_id === $tenant->id, 404);
        abort_unless($policy->canManageTenantCatalog($request->user(), $tenant), 403);

        $supplier->fill($this->validated($request, $tenant, $supplier))->save();

        return response()->json(['data' => $supplier->refresh()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Tenant $tenant, ?InventorySupplier $supplier = null): array
    {
        $data = $request->validate([
            'name' => [$supplier ? 'sometimes' : 'required', 'string', 'max:160'],
            'tax_id' => [
                'nullable',
                'string',
                'max:32',
                Rule::unique('inventory_suppliers', 'tax_id')->where('tenant_id', $tenant->id)->ignore($supplier?->id),
            ],
            'nrc' => ['nullable', 'string', 'max:32'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'address' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
        ]);

        foreach (['name', 'tax_id', 'nrc', 'phone', 'email', 'address', 'status'] as $key) {
            if (array_key_exists($key, $data) && is_string($data[$key])) {
                $data[$key] = trim($data[$key]);
            }
        }
        if (! $supplier) {
            $data['status'] = $data['status'] ?? 'active';
        }

        return $data;
    }
}

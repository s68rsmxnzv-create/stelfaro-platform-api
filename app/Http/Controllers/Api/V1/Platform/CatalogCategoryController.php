<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\CatalogCategory;
use App\Models\Tenant;
use App\Services\PlatformAccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CatalogCategoryController extends Controller
{
    public function index(Request $request, Tenant $tenant, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canViewTenantCatalog($request->user(), $tenant), 403);

        $query = $tenant->catalogCategories()
            ->withCount('items')
            ->orderBy('name');

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        return response()->json([
            'data' => $query->get()->map(fn (CatalogCategory $category): array => $this->payload($category))->values(),
        ]);
    }

    public function store(Request $request, Tenant $tenant, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canManageTenantCatalog($request->user(), $tenant), 403);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('catalog_categories', 'name')->where('tenant_id', $tenant->id),
            ],
            'kind' => ['nullable', 'string', Rule::in(['product', 'service', 'mixed'])],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
        ]);

        $category = $tenant->catalogCategories()->create([
            'name' => trim((string) $validated['name']),
            'kind' => (string) ($validated['kind'] ?? 'mixed'),
            'status' => (string) ($validated['status'] ?? 'active'),
        ]);

        return response()->json([
            'data' => $this->payload($category->loadCount('items')),
        ], 201);
    }

    public function update(Request $request, Tenant $tenant, CatalogCategory $category, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($category->tenant_id === $tenant->id, 404);
        abort_unless($policy->canManageTenantCatalog($request->user(), $tenant), 403);

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:120',
                Rule::unique('catalog_categories', 'name')
                    ->where('tenant_id', $tenant->id)
                    ->ignore($category->id),
            ],
            'kind' => ['sometimes', 'string', Rule::in(['product', 'service', 'mixed'])],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
        ]);

        if (array_key_exists('name', $validated)) {
            $validated['name'] = trim((string) $validated['name']);
        }

        $category->fill($validated)->save();

        return response()->json([
            'data' => $this->payload($category->refresh()->loadCount('items')),
        ]);
    }

    public function destroy(Request $request, Tenant $tenant, CatalogCategory $category, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($category->tenant_id === $tenant->id, 404);
        abort_unless($policy->canManageTenantCatalog($request->user(), $tenant), 403);

        $category->forceFill(['status' => 'inactive'])->save();

        return response()->json([
            'data' => $this->payload($category->refresh()->loadCount('items')),
        ]);
    }

    private function payload(CatalogCategory $category): array
    {
        return [
            'id' => $category->id,
            'tenant_id' => $category->tenant_id,
            'name' => $category->name,
            'kind' => $category->kind,
            'status' => $category->status,
            'items_count' => $category->items_count ?? null,
            'legacy_reference' => $category->legacy_reference,
            'created_at' => $category->created_at?->toISOString(),
            'updated_at' => $category->updated_at?->toISOString(),
        ];
    }
}

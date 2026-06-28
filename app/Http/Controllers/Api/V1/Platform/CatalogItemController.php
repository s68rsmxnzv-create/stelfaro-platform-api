<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\CatalogCategory;
use App\Models\CatalogItem;
use App\Models\Tenant;
use App\Services\PlatformAccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CatalogItemController extends Controller
{
    public function index(Request $request, Tenant $tenant, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canViewTenantCatalog($request->user(), $tenant), 403);

        $query = $tenant->catalogItems()
            ->with('category')
            ->orderBy('name');

        if ($request->filled('q')) {
            $term = trim((string) $request->query('q'));
            $query->where(function ($sub) use ($term): void {
                $sub->where('name', 'like', "%{$term}%")
                    ->orWhere('sku', 'like', "%{$term}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        if ($request->filled('item_type')) {
            $query->where('item_type', (string) $request->query('item_type'));
        }

        if ($request->has('controls_inventory')) {
            $query->where('controls_inventory', filter_var($request->query('controls_inventory'), FILTER_VALIDATE_BOOL));
        }

        if ($request->filled('category_id')) {
            $query->where('catalog_category_id', (int) $request->query('category_id'));
        }

        $items = $query->paginate((int) min(max((int) $request->query('per_page', 50), 1), 100));

        return response()->json([
            'data' => $items->getCollection()->map(fn (CatalogItem $item): array => $this->payload($item))->values(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'last_page' => $items->lastPage(),
            ],
        ]);
    }

    public function store(Request $request, Tenant $tenant, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canManageTenantCatalog($request->user(), $tenant), 403);

        $validated = $this->validatedPayload($request, $tenant);
        if (blank($validated['sku'] ?? null)) {
            $validated['sku'] = $this->nextSku($tenant, $validated);
        }
        $item = $tenant->catalogItems()->create($validated);

        return response()->json([
            'data' => $this->payload($item->load('category')),
        ], 201);
    }

    public function update(Request $request, Tenant $tenant, CatalogItem $item, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($item->tenant_id === $tenant->id, 404);
        abort_unless($policy->canManageTenantCatalog($request->user(), $tenant), 403);

        $validated = $this->validatedPayload($request, $tenant, $item);
        $item->fill($validated)->save();

        return response()->json([
            'data' => $this->payload($item->refresh()->load('category')),
        ]);
    }

    public function destroy(Request $request, Tenant $tenant, CatalogItem $item, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($item->tenant_id === $tenant->id, 404);
        abort_unless($policy->canManageTenantCatalog($request->user(), $tenant), 403);

        $item->forceFill(['status' => 'inactive'])->save();

        return response()->json([
            'data' => $this->payload($item->refresh()->load('category')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPayload(Request $request, Tenant $tenant, ?CatalogItem $item = null): array
    {
        $rules = [
            'catalog_category_id' => [
                'nullable',
                'integer',
                Rule::exists('catalog_categories', 'id')->where('tenant_id', $tenant->id),
            ],
            'sku' => [
                'nullable',
                'string',
                'max:60',
                Rule::unique('catalog_items', 'sku')
                    ->where('tenant_id', $tenant->id)
                    ->ignore($item?->id),
            ],
            'name' => [$item ? 'sometimes' : 'required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:255'],
            'item_type' => [$item ? 'sometimes' : 'required', 'string', Rule::in(['product', 'service', 'part', 'labor', 'other'])],
            'unit_code' => ['nullable', 'string', 'max:10'],
            'unit_name' => ['nullable', 'string', 'max:60'],
            'units_per_package' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'taxable' => ['nullable', 'boolean'],
            'controls_inventory' => ['nullable', 'boolean'],
            'base_price' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'base_price_includes_tax' => ['nullable', 'boolean'],
            'reference_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999.9999'],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
        ];

        $validated = $request->validate($rules);

        foreach (['sku', 'name', 'description', 'unit_code', 'unit_name', 'item_type', 'status'] as $key) {
            if (array_key_exists($key, $validated) && is_string($validated[$key])) {
                $validated[$key] = trim($validated[$key]);
            }
        }

        $itemType = (string) ($validated['item_type'] ?? $item?->item_type ?? 'product');
        if (in_array($itemType, ['service', 'labor'], true)) {
            $validated['controls_inventory'] = false;
        }

        if (array_key_exists('reference_cost', $validated)) {
            $validated['cost_source'] = $validated['reference_cost'] !== null ? 'reference' : 'none';
        }

        if (! $item) {
            $referenceCost = $validated['reference_cost'] ?? null;
            $validated['unit_code'] = (string) ($validated['unit_code'] ?? '59');
            $validated['units_per_package'] = (int) ($validated['units_per_package'] ?? 1);
            $validated['taxable'] = (bool) ($validated['taxable'] ?? true);
            $validated['controls_inventory'] = (bool) ($validated['controls_inventory'] ?? false);
            $validated['base_price'] = $validated['base_price'] ?? 0;
            $validated['base_price_includes_tax'] = (bool) ($validated['base_price_includes_tax'] ?? false);
            $validated['cost_source'] = $referenceCost !== null ? 'reference' : 'none';
            $validated['stock_quantity'] = 0;
            $validated['status'] = (string) ($validated['status'] ?? 'active');
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function nextSku(Tenant $tenant, array $payload): string
    {
        $categoryId = isset($payload['catalog_category_id']) ? (int) $payload['catalog_category_id'] : null;
        $category = $categoryId
            ? CatalogCategory::query()
                ->where('tenant_id', $tenant->id)
                ->find($categoryId)
            : null;
        $categoryPrefix = $this->skuSegment($category?->name ?: 'CAT');
        $itemPrefix = $this->skuSegment((string) ($payload['name'] ?? 'ITEM'));
        $base = "{$categoryPrefix}-{$itemPrefix}";
        $next = CatalogItem::query()
            ->where('tenant_id', $tenant->id)
            ->when($categoryId, fn ($query) => $query->where('catalog_category_id', $categoryId))
            ->count() + 1;

        do {
            $candidate = "{$base}-".str_pad((string) $next, 3, '0', STR_PAD_LEFT);
            $exists = CatalogItem::query()
                ->where('tenant_id', $tenant->id)
                ->where('sku', $candidate)
                ->exists();
            $next++;
        } while ($exists);

        return $candidate;
    }

    private function skuSegment(string $value): string
    {
        $ascii = Str::ascii($value);
        $words = preg_split('/[^A-Za-z0-9]+/', strtoupper($ascii), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $segment = collect($words)
            ->take(2)
            ->map(fn (string $word): string => substr($word, 0, 4))
            ->implode('');

        return substr($segment !== '' ? $segment : 'ITEM', 0, 8);
    }

    private function payload(CatalogItem $item): array
    {
        return [
            'id' => $item->id,
            'tenant_id' => $item->tenant_id,
            'catalog_category_id' => $item->catalog_category_id,
            'category' => $item->category ? [
                'id' => $item->category->id,
                'name' => $item->category->name,
                'kind' => $item->category->kind,
            ] : null,
            'legacy_item_id' => $item->legacy_item_id,
            'sku' => $item->sku,
            'name' => $item->name,
            'description' => $item->description,
            'item_type' => $item->item_type,
            'unit_code' => $item->unit_code,
            'unit_name' => $item->unit_name,
            'units_per_package' => $item->units_per_package,
            'taxable' => (bool) $item->taxable,
            'controls_inventory' => (bool) $item->controls_inventory,
            'base_price' => (float) $item->base_price,
            'base_price_includes_tax' => (bool) $item->base_price_includes_tax,
            'reference_cost' => $item->reference_cost !== null ? (float) $item->reference_cost : null,
            'cost_source' => $item->cost_source,
            'stock_quantity' => (float) $item->stock_quantity,
            'status' => $item->status,
            'metadata' => $item->metadata,
            'created_at' => $item->created_at?->toISOString(),
            'updated_at' => $item->updated_at?->toISOString(),
        ];
    }
}

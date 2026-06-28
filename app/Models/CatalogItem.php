<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'catalog_category_id',
    'legacy_item_id',
    'sku',
    'name',
    'description',
    'item_type',
    'unit_code',
    'unit_name',
    'units_per_package',
    'taxable',
    'controls_inventory',
    'base_price',
    'base_price_includes_tax',
    'reference_cost',
    'cost_source',
    'stock_quantity',
    'status',
    'metadata',
])]
class CatalogItem extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'taxable' => 'boolean',
            'controls_inventory' => 'boolean',
            'base_price_includes_tax' => 'boolean',
            'base_price' => 'decimal:2',
            'reference_cost' => 'decimal:4',
            'stock_quantity' => 'decimal:3',
            'units_per_package' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CatalogCategory::class, 'catalog_category_id');
    }
}

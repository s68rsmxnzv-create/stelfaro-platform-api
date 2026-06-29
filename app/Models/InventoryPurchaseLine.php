<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id',
    'inventory_purchase_id',
    'catalog_item_id',
    'description_snapshot',
    'unit_code',
    'unit_name',
    'quantity',
    'input_unit_cost',
    'unit_cost',
    'base_unit_cost',
    'tax_unit_amount',
    'tax_rate',
    'total_unit_amount',
    'tax_amount',
    'line_total',
    'price_includes_tax',
    'no_inventory',
    'controls_inventory_snapshot',
    'inventory_quantity',
])]
class InventoryPurchaseLine extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'input_unit_cost' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'base_unit_cost' => 'decimal:4',
            'tax_unit_amount' => 'decimal:4',
            'tax_rate' => 'decimal:6',
            'total_unit_amount' => 'decimal:4',
            'tax_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
            'price_includes_tax' => 'boolean',
            'no_inventory' => 'boolean',
            'controls_inventory_snapshot' => 'boolean',
            'inventory_quantity' => 'decimal:3',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(InventoryPurchase::class, 'inventory_purchase_id');
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function lots(): HasMany
    {
        return $this->hasMany(InventoryLot::class);
    }
}

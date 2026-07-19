<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'inventory_sale_id', 'catalog_item_id', 'line_origin', 'inherited_from_line_id', 'description_snapshot', 'quantity', 'inherited_quantity', 'unit_price', 'discount_amount', 'net_total', 'reference_unit_cost'])]
class InventorySaleLine extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'inherited_quantity' => 'decimal:3',
            'unit_price' => 'decimal:4',
            'discount_amount' => 'decimal:2',
            'net_total' => 'decimal:2',
            'reference_unit_cost' => 'decimal:4',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(InventorySale::class, 'inventory_sale_id');
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function inheritedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'inherited_from_line_id');
    }
}

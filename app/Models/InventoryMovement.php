<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'core_sucursal_id', 'core_sucursal_code', 'core_sucursal_name', 'catalog_item_id', 'inventory_lot_id', 'movement_type', 'reason', 'quantity', 'unit_cost', 'balance_after', 'reference_type', 'reference_id', 'reference_number', 'notes', 'created_by'])]
class InventoryMovement extends Model
{
    protected function casts(): array
    {
        return [
            'core_sucursal_id' => 'integer',
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:4',
            'balance_after' => 'decimal:3',
        ];
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'inventory_lot_id');
    }
}

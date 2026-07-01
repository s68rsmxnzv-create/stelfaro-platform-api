<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'core_sucursal_id', 'core_sucursal_code', 'core_sucursal_name', 'catalog_item_id', 'inventory_supplier_id', 'inventory_purchase_id', 'inventory_purchase_line_id', 'lot_code', 'received_date', 'unit_cost', 'initial_quantity', 'available_quantity', 'status'])]
class InventoryLot extends Model
{
    protected function casts(): array
    {
        return [
            'core_sucursal_id' => 'integer',
            'received_date' => 'date',
            'unit_cost' => 'decimal:4',
            'initial_quantity' => 'decimal:3',
            'available_quantity' => 'decimal:3',
        ];
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(InventorySupplier::class, 'inventory_supplier_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(InventorySaleAllocation::class);
    }
}

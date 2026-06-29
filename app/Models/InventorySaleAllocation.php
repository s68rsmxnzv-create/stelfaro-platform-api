<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'inventory_reservation_line_id', 'catalog_item_id', 'inventory_lot_id', 'quantity', 'unit_cost'])]
class InventorySaleAllocation extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function reservationLine(): BelongsTo
    {
        return $this->belongsTo(InventoryReservationLine::class, 'inventory_reservation_line_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'inventory_lot_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'from_core_sucursal_id', 'from_core_sucursal_code', 'from_core_sucursal_name', 'to_core_sucursal_id', 'to_core_sucursal_code', 'to_core_sucursal_name', 'transfer_number', 'transfer_date', 'status', 'notes', 'created_by'])]
class InventoryTransfer extends Model
{
    protected function casts(): array
    {
        return [
            'from_core_sucursal_id' => 'integer',
            'to_core_sucursal_id' => 'integer',
            'transfer_date' => 'date',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryTransferLine::class);
    }
}

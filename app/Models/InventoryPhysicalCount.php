<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'core_sucursal_id', 'core_sucursal_code', 'core_sucursal_name', 'count_date', 'status', 'notes', 'created_by'])]
class InventoryPhysicalCount extends Model
{
    protected function casts(): array
    {
        return [
            'core_sucursal_id' => 'integer',
            'count_date' => 'date',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryPhysicalCountLine::class);
    }
}

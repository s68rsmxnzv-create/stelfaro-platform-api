<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'core_sucursal_id', 'core_sucursal_code', 'core_sucursal_name', 'source_type', 'source_id', 'source_number', 'sale_date', 'status', 'metadata', 'reversed_at', 'created_by'])]
class InventorySale extends Model
{
    protected function casts(): array
    {
        return [
            'core_sucursal_id' => 'integer',
            'sale_date' => 'date',
            'metadata' => 'array',
            'reversed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InventorySaleLine::class);
    }
}

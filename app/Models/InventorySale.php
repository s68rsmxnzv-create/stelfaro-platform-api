<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'core_sucursal_id', 'core_sucursal_code', 'core_sucursal_name', 'source_type', 'source_id', 'source_number', 'sale_date', 'operation_kind', 'fiscal_document_type', 'reporting_sign', 'net_amount', 'tax_amount', 'total_amount', 'status', 'replacement_of_sale_id', 'metadata', 'reversed_at', 'superseded_at', 'created_by'])]
class InventorySale extends Model
{
    protected function casts(): array
    {
        return [
            'core_sucursal_id' => 'integer',
            'sale_date' => 'date',
            'reporting_sign' => 'integer',
            'net_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'metadata' => 'array',
            'reversed_at' => 'datetime',
            'superseded_at' => 'datetime',
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

    public function replacementOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replacement_of_sale_id');
    }

    public function replacements(): HasMany
    {
        return $this->hasMany(self::class, 'replacement_of_sale_id');
    }
}

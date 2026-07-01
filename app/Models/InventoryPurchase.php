<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id',
    'inventory_supplier_id',
    'core_sucursal_id',
    'core_sucursal_code',
    'core_sucursal_name',
    'purchase_number',
    'document_type',
    'document_mode',
    'document_number',
    'payment_condition',
    'document_total',
    'is_consumable',
    'purchase_date',
    'subtotal',
    'tax_amount',
    'tax_perceived',
    'fovial_per_unit',
    'cotrans_per_unit',
    'other_non_taxable_total',
    'total',
    'f07_operation_type',
    'f07_classification',
    'f07_sector',
    'f07_cost_expense_type',
    'supplier_snapshot',
    'import_metadata',
    'status',
    'created_by',
])]
class InventoryPurchase extends Model
{
    protected function casts(): array
    {
        return [
            'purchase_number' => 'integer',
            'core_sucursal_id' => 'integer',
            'purchase_date' => 'date',
            'document_total' => 'decimal:2',
            'is_consumable' => 'boolean',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'tax_perceived' => 'decimal:2',
            'fovial_per_unit' => 'decimal:4',
            'cotrans_per_unit' => 'decimal:4',
            'other_non_taxable_total' => 'decimal:2',
            'total' => 'decimal:2',
            'f07_operation_type' => 'integer',
            'f07_classification' => 'integer',
            'f07_sector' => 'integer',
            'f07_cost_expense_type' => 'integer',
            'supplier_snapshot' => 'array',
            'import_metadata' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(InventorySupplier::class, 'inventory_supplier_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryPurchaseLine::class);
    }
}

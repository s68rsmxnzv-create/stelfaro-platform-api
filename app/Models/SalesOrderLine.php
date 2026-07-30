<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'sales_order_id', 'catalog_item_id', 'line_origin', 'description_snapshot', 'sku_snapshot', 'unit_code', 'taxable', 'price_includes_tax', 'quantity', 'unit_price', 'discount_amount', 'line_total', 'reference_unit_cost'])]
class SalesOrderLine extends Model
{
    protected function casts(): array
    {
        return ['taxable' => 'boolean', 'price_includes_tax' => 'boolean', 'quantity' => 'decimal:3', 'unit_price' => 'decimal:4', 'discount_amount' => 'decimal:2', 'line_total' => 'decimal:2'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }
}

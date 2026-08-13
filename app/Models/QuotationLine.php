<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'quotation_id', 'catalog_item_id', 'description_snapshot', 'unit_code', 'quantity', 'unit_price', 'price_includes_tax', 'discount_amount', 'tax_amount', 'line_total'])]
class QuotationLine extends Model
{
    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_price' => 'decimal:4', 'price_includes_tax' => 'boolean', 'discount_amount' => 'decimal:2', 'tax_amount' => 'decimal:2', 'line_total' => 'decimal:2'];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }
}

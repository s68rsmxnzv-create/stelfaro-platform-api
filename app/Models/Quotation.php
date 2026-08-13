<?php

namespace App\Models;

use App\Support\CustomerNameNormalizer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['tenant_id', 'idempotency_key', 'public_token', 'quotation_number', 'version', 'core_sucursal_id', 'core_sucursal_code', 'core_sucursal_name', 'core_customer_id', 'customer_name', 'customer_phone', 'customer_email', 'title', 'status', 'approval_method', 'approval_note', 'subtotal', 'discount_total', 'tax_total', 'total', 'requested_deposit', 'valid_until', 'terms', 'notes', 'created_by', 'approved_by', 'sent_at', 'accepted_at', 'rejected_at', 'converted_at'])]
class Quotation extends Model
{
    protected static function booted(): void
    {
        static::saving(function (Quotation $quotation): void {
            $quotation->customer_name = CustomerNameNormalizer::normalize($quotation->customer_name);
        });
    }

    protected function casts(): array
    {
        return ['subtotal' => 'decimal:2', 'discount_total' => 'decimal:2', 'tax_total' => 'decimal:2', 'total' => 'decimal:2', 'requested_deposit' => 'decimal:2', 'valid_until' => 'date', 'sent_at' => 'datetime', 'accepted_at' => 'datetime', 'rejected_at' => 'datetime', 'converted_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuotationLine::class);
    }

    public function order(): HasOne
    {
        return $this->hasOne(SalesOrder::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}

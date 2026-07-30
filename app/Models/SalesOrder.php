<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'idempotency_key', 'quotation_id', 'order_number', 'core_sucursal_id', 'core_sucursal_code', 'core_sucursal_name', 'core_customer_id', 'customer_name', 'customer_phone', 'customer_email', 'title', 'work_type', 'status', 'financial_status', 'billing_status', 'dte_type', 'core_dte_document_id', 'dte_number', 'dte_generation_code', 'subtotal', 'discount_total', 'total', 'notes', 'cancellation_reason', 'created_by', 'delivered_by', 'delivered_at', 'completed_at', 'cancelled_at', 'invoiced_at'])]
class SalesOrder extends Model
{
    protected function casts(): array
    {
        return ['subtotal' => 'decimal:2', 'discount_total' => 'decimal:2', 'total' => 'decimal:2', 'delivered_at' => 'datetime', 'completed_at' => 'datetime', 'cancelled_at' => 'datetime', 'invoiced_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalesOrderPayment::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'cash_register_id', 'cash_session_id', 'cash_expense_id', 'workshop_order_id', 'sales_order_id', 'direction', 'kind', 'method', 'amount', 'description', 'reference', 'source_type', 'source_id', 'idempotency_key', 'metadata', 'reversal_of_id', 'reversed_at', 'reversed_by', 'created_by', 'occurred_at'])]
class CashMovement extends Model
{
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'metadata' => 'array', 'occurred_at' => 'datetime', 'reversed_at' => 'datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashSession::class, 'cash_session_id');
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class, 'cash_register_id');
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(CashExpense::class, 'cash_expense_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(WorkshopOrder::class, 'workshop_order_id');
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }
}

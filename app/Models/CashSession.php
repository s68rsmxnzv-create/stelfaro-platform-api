<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'cash_register_id', 'business_date', 'opened_by', 'closed_by', 'opening_balance', 'opening_source', 'expected_balance', 'declared_balance', 'difference', 'status', 'count_status', 'opening_notes', 'closing_notes', 'opened_at', 'closed_at'])]
class CashSession extends Model
{
    protected function casts(): array
    {
        return ['business_date' => 'date', 'opening_balance' => 'decimal:2', 'expected_balance' => 'decimal:2', 'declared_balance' => 'decimal:2', 'difference' => 'decimal:2', 'opened_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class, 'cash_register_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }
}

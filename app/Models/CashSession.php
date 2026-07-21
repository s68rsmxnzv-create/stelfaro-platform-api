<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'cash_register_id', 'opened_by', 'closed_by', 'opening_balance', 'expected_balance', 'declared_balance', 'difference', 'status', 'opening_notes', 'closing_notes', 'opened_at', 'closed_at'])]
class CashSession extends Model
{
    protected function casts(): array
    {
        return ['opening_balance' => 'decimal:2', 'expected_balance' => 'decimal:2', 'declared_balance' => 'decimal:2', 'difference' => 'decimal:2', 'opened_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class, 'cash_register_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }
}

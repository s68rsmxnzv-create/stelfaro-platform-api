<?php

namespace App\Models;

use App\Support\CustomerNameNormalizer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'source_type', 'source_id', 'source_number', 'core_customer_id', 'customer_name', 'original_amount', 'paid_amount', 'refunded_amount', 'balance', 'status', 'recognized_at', 'due_at', 'settled_at', 'cancelled_at', 'metadata'])]
class ReceivableAccount extends Model
{
    protected static function booted(): void
    {
        static::saving(function (ReceivableAccount $account): void {
            $account->customer_name = CustomerNameNormalizer::normalize($account->customer_name);
        });
    }

    protected function casts(): array
    {
        return ['original_amount' => 'decimal:2', 'paid_amount' => 'decimal:2', 'refunded_amount' => 'decimal:2', 'balance' => 'decimal:2', 'recognized_at' => 'datetime', 'due_at' => 'datetime', 'settled_at' => 'datetime', 'cancelled_at' => 'datetime', 'metadata' => 'array'];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(ReceivableEntry::class);
    }
}

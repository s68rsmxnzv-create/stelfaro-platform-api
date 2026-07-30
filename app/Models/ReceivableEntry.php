<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'receivable_account_id', 'entry_type', 'amount', 'source_type', 'source_id', 'reference', 'notes', 'occurred_at'])]
class ReceivableEntry extends Model
{
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'occurred_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ReceivableAccount::class, 'receivable_account_id');
    }
}

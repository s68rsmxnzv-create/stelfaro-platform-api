<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'workshop_order_id', 'received_by', 'kind', 'amount', 'method', 'reference', 'notes', 'received_at', 'voided_at', 'voided_by'])]
class WorkshopOrderPayment extends Model
{
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'received_at' => 'datetime', 'voided_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(WorkshopOrder::class, 'workshop_order_id');
    }
}

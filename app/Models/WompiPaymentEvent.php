<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'tenant_subscription_id',
    'transaction_id',
    'payment_attempt_id',
    'payment_link_id',
    'commerce_identifier',
    'customer_email',
    'amount',
    'result',
    'is_productive',
    'hash_valid',
    'status',
    'raw_payload',
    'headers',
    'processed_at',
])]
class WompiPaymentEvent extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'is_productive' => 'boolean',
            'hash_valid' => 'boolean',
            'raw_payload' => 'array',
            'headers' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(TenantSubscription::class, 'tenant_subscription_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'idempotency_key', 'status', 'source_type', 'source_id', 'source_number', 'metadata', 'confirmed_at', 'released_at', 'created_by'])]
class InventoryReservation extends Model
{
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_RELEASED = 'released';
    public const STATUS_REVERSED = 'reversed';

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'confirmed_at' => 'datetime',
            'released_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryReservationLine::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'workshop_order_id', 'workshop_device_id', 'public_token_hash', 'public_token_encrypted', 'pin_hash', 'pin_encrypted', 'expires_at', 'last_accessed_at', 'revoked_at'])]
class WorkshopDeviceAccess extends Model
{
    protected function casts(): array
    {
        return ['public_token_encrypted' => 'encrypted', 'pin_encrypted' => 'encrypted', 'expires_at' => 'datetime', 'last_accessed_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(WorkshopOrder::class, 'workshop_order_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(WorkshopDevice::class, 'workshop_device_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(WorkshopDeviceAccessSession::class);
    }
}

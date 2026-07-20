<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['workshop_device_access_id', 'token_hash', 'ip_address', 'user_agent', 'expires_at', 'last_seen_at', 'revoked_at'])]
class WorkshopDeviceAccessSession extends Model
{
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'last_seen_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function access(): BelongsTo
    {
        return $this->belongsTo(WorkshopDeviceAccess::class, 'workshop_device_access_id');
    }
}

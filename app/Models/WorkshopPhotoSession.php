<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'workshop_order_id', 'token_hash', 'expires_at', 'revoked_at'])]
class WorkshopPhotoSession extends Model
{
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(WorkshopOrder::class, 'workshop_order_id');
    }
}

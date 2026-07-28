<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'name', 'device_name', 'token_hash', 'status', 'last_seen_at'])]
class AndroidPrintAgent extends Model
{
    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(AndroidPrintJob::class, 'agent_id');
    }
}

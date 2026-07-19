<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'tenant_id', 'category', 'title', 'message', 'action_url', 'due_date',
    'source_type', 'source_id', 'dedupe_key', 'metadata', 'read_at',
])]
class InternalNotification extends Model
{
    protected function casts(): array
    {
        return [
            'due_date' => 'date:Y-m-d',
            'metadata' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}

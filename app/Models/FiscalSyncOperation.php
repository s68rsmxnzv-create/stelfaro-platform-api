<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'kind', 'idempotency_key', 'payload_hash', 'status', 'core_resource_id', 'payload', 'result', 'attempts', 'last_attempt_at', 'next_attempt_at', 'completed_at', 'last_error', 'created_by'])]
class FiscalSyncOperation extends Model
{
    public const KIND_DTE_ISSUE = 'dte_issue';

    public const KIND_MH_INVALIDATION = 'mh_invalidation';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'attempts' => 'integer',
            'last_attempt_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

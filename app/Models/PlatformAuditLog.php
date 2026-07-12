<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'tenant_id',
    'action',
    'resource_type',
    'resource_id',
    'result',
    'status_code',
    'ip_address',
    'user_agent',
    'method',
    'url',
    'request_id',
    'session_id_hash',
    'metadata',
])]
class PlatformAuditLog extends Model
{
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
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

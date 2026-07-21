<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id', 'requested_by_user_id', 'assigned_to_user_id', 'public_id', 'idempotency_key', 'type', 'status',
    'subject', 'description', 'payload', 'admin_response', 'reviewed_at', 'completed_at',
])]
class TenantRequest extends Model
{
    public const TYPES = [
        'user_access',
        'branch',
        'point_of_sale',
        'fiscal_identity',
        'certificate',
        'mh_credentials',
        'correlatives',
        'subscription',
        'app_access',
        'data_migration',
        'support',
    ];

    public const STATUSES = [
        'pending',
        'in_review',
        'needs_information',
        'approved',
        'completed',
        'rejected',
        'cancelled',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'reviewed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }
}

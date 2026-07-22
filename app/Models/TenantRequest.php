<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id', 'requested_by_user_id', 'assigned_to_user_id', 'fulfilled_user_id', 'fulfilled_resource_type', 'fulfilled_resource_id', 'public_id', 'idempotency_key', 'type', 'status',
    'subject', 'description', 'payload', 'reviewed_payload', 'admin_response', 'temporary_password', 'credentials_available_at',
    'credentials_revealed_at', 'reviewed_at', 'completed_at',
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
            'reviewed_payload' => 'array',
            'temporary_password' => 'encrypted',
            'credentials_available_at' => 'datetime',
            'credentials_revealed_at' => 'datetime',
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

    public function fulfilledUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fulfilled_user_id');
    }
}

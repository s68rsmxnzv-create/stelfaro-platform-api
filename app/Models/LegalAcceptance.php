<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'event_uuid', 'user_id', 'tenant_id', 'membership_id', 'legal_document_id',
    'document_type', 'document_version', 'document_hash', 'acceptance_version',
    'acceptance_text', 'environment', 'role_at_acceptance', 'user_email_hash',
    'tenant_slug', 'tenant_name', 'authentication_method', 'password_changed_at',
    'accepted_at', 'ip_address', 'user_agent', 'session_id_hash', 'request_id', 'metadata',
])]
class LegalAcceptance extends Model
{
    protected function casts(): array
    {
        return [
            'password_changed_at' => 'datetime',
            'accepted_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(LegalDocument::class, 'legal_document_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(UserTenantMembership::class, 'membership_id');
    }
}

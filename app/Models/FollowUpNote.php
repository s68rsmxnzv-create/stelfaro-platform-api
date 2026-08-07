<?php

namespace App\Models;

use App\Support\CustomerNameNormalizer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id', 'idempotency_key', 'created_by', 'core_customer_id', 'person_name', 'person_phone', 'person_email',
    'title', 'description', 'category', 'occurred_on', 'remind_at', 'reminder_notified_at',
    'status', 'resolution_type', 'resolution_note', 'resolution_reference', 'resolved_by', 'resolved_at',
])]
class FollowUpNote extends Model
{
    protected static function booted(): void
    {
        static::saving(function (FollowUpNote $note): void {
            $note->person_name = CustomerNameNormalizer::normalize($note->person_name);
        });
    }

    protected function casts(): array
    {
        return [
            'occurred_on' => 'date:Y-m-d',
            'remind_at' => 'datetime',
            'reminder_notified_at' => 'datetime',
            'resolved_at' => 'datetime',
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

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}

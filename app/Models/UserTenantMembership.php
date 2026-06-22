<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'tenant_id', 'role', 'status', 'is_default', 'metadata'])]
class UserTenantMembership extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
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

    public function fiscalAssignments(): HasMany
    {
        return $this->hasMany(UserFiscalAssignment::class, 'membership_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['slug', 'name', 'status', 'primary_app_id', 'metadata'])]
class Tenant extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function primaryApp(): BelongsTo
    {
        return $this->belongsTo(PlatformApp::class, 'primary_app_id');
    }

    public function appAccesses(): HasMany
    {
        return $this->hasMany(TenantAppAccess::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(UserTenantMembership::class);
    }
}

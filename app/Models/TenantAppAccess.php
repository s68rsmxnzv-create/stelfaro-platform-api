<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'platform_app_id', 'status', 'is_default', 'metadata'])]
class TenantAppAccess extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(PlatformApp::class, 'platform_app_id');
    }
}

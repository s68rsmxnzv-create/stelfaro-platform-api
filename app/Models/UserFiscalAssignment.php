<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['membership_id', 'core_empresa_id', 'core_sucursal_id', 'core_punto_venta_id', 'is_default', 'status', 'metadata'])]
class UserFiscalAssignment extends Model
{
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(UserTenantMembership::class, 'membership_id');
    }
}

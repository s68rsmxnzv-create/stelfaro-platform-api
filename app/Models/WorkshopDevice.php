<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'workshop_customer_id', 'type', 'brand', 'model', 'color', 'imei', 'serial_number', 'identifier_not_visible', 'power_status', 'functional_tests', 'is_locked', 'access_type', 'access_secret', 'metadata'])]
class WorkshopDevice extends Model
{
    protected function casts(): array
    {
        return ['identifier_not_visible' => 'boolean', 'functional_tests' => 'array', 'is_locked' => 'boolean', 'access_secret' => 'encrypted', 'metadata' => 'array'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(WorkshopCustomer::class, 'workshop_customer_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(WorkshopOrder::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'workshop_customer_id', 'core_sucursal_id', 'core_sucursal_code', 'core_sucursal_name', 'received_by', 'ticket_number', 'received_at'])]
class WorkshopReception extends Model
{
    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
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

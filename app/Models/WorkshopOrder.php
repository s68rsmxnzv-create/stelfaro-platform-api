<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'workshop_device_id', 'received_by', 'assigned_to', 'ticket_number', 'status', 'priority', 'reported_fault', 'physical_condition', 'physical_conditions', 'accessories', 'diagnosis', 'estimated_total', 'received_at', 'completed_at', 'delivered_at'])]
class WorkshopOrder extends Model
{
    protected function casts(): array
    {
        return ['physical_conditions' => 'array', 'accessories' => 'array', 'estimated_total' => 'decimal:2', 'received_at' => 'datetime', 'completed_at' => 'datetime', 'delivered_at' => 'datetime'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(WorkshopDevice::class, 'workshop_device_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(WorkshopOrderPayment::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(WorkshopOrderPhoto::class);
    }
}

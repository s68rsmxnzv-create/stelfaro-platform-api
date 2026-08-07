<?php

namespace App\Models;

use App\Support\CustomerNameNormalizer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'core_customer_id', 'name', 'phone', 'email'])]
class WorkshopCustomer extends Model
{
    protected static function booted(): void
    {
        static::saving(function (WorkshopCustomer $customer): void {
            $customer->name = CustomerNameNormalizer::normalize($customer->name);
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(WorkshopDevice::class);
    }
}

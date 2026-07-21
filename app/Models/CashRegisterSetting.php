<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'cash_register_id', 'timezone', 'default_opening_balance', 'carry_forward_balance', 'auto_open_enabled', 'auto_open_time', 'auto_close_enabled', 'auto_close_time', 'close_grace_minutes', 'working_days', 'non_working_dates', 'use_official_holidays', 'allow_non_cash_when_closed', 'active', 'updated_by'])]
class CashRegisterSetting extends Model
{
    protected function casts(): array
    {
        return ['default_opening_balance' => 'decimal:2', 'carry_forward_balance' => 'boolean', 'auto_open_enabled' => 'boolean', 'auto_close_enabled' => 'boolean', 'close_grace_minutes' => 'integer', 'working_days' => 'array', 'non_working_dates' => 'array', 'use_official_holidays' => 'boolean', 'allow_non_cash_when_closed' => 'boolean', 'active' => 'boolean'];
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class, 'cash_register_id');
    }
}

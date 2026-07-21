<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\CashRegister;
use App\Models\CashRegisterSetting;
use App\Models\Tenant;
use App\Services\Cash\CashService;
use App\Services\PlatformAccessPolicy;
use App\Services\PlatformAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashSettingsController extends Controller
{
    public function index(Request $request, Tenant $tenant, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canViewTenantCatalog($request->user(), $tenant), 403);

        return response()->json(['data' => $tenant->cashRegisters()->with('setting')->orderBy('core_sucursal_name')->orderBy('name')->get()->map(fn (CashRegister $register) => $this->payload($register))->values()]);
    }

    public function upsert(Request $request, Tenant $tenant, PlatformAccessPolicy $policy, CashService $cash, PlatformAuditLogger $audit): JsonResponse
    {
        abort_unless($policy->canManageTenantCatalog($request->user(), $tenant), 403);
        $data = $request->validate([
            'core_sucursal_id' => ['required', 'integer', 'min:1'], 'core_sucursal_code' => ['required', 'string', 'max:30'], 'core_sucursal_name' => ['required', 'string', 'max:160'], 'name' => ['required', 'string', 'max:120'],
            'timezone' => ['required', 'timezone'], 'default_opening_balance' => ['required', 'numeric', 'min:0'], 'carry_forward_balance' => ['required', 'boolean'],
            'auto_open_enabled' => ['required', 'boolean'], 'auto_open_time' => ['nullable', 'date_format:H:i', 'required_if:auto_open_enabled,true'],
            'auto_close_enabled' => ['required', 'boolean'], 'auto_close_time' => ['nullable', 'date_format:H:i', 'required_if:auto_close_enabled,true'],
            'close_grace_minutes' => ['required', 'integer', 'min:0', 'max:180'], 'working_days' => ['required', 'array', 'min:1'], 'working_days.*' => ['integer', 'between:1,7'],
            'non_working_dates' => ['nullable', 'array'], 'non_working_dates.*' => ['date_format:Y-m-d'], 'use_official_holidays' => ['required', 'boolean'], 'allow_non_cash_when_closed' => ['required', 'boolean'], 'active' => ['required', 'boolean'],
        ]);
        $register = CashRegister::query()->where('tenant_id', $tenant->id)->where('core_sucursal_id', $data['core_sucursal_id'])->first()
            ?? CashRegister::query()->where('tenant_id', $tenant->id)->whereNull('core_sucursal_id')->whereDoesntHave('setting')->oldest()->first()
            ?? $cash->defaultRegister($tenant, $data);
        $register->forceFill(['core_sucursal_id' => $data['core_sucursal_id'], 'core_sucursal_code' => $data['core_sucursal_code'], 'core_sucursal_name' => $data['core_sucursal_name'], 'name' => $data['name'], 'status' => $data['active'] ? 'active' : 'inactive'])->save();
        CashRegisterSetting::query()->updateOrCreate(['cash_register_id' => $register->id], [...$data, 'tenant_id' => $tenant->id, 'updated_by' => $request->user()->id]);
        $audit->record($request, 'cash.settings.updated', ['cash_register_id' => $register->id, 'core_sucursal_id' => $register->core_sucursal_id]);

        return response()->json(['data' => $this->payload($register->refresh()->load('setting'))]);
    }

    public function update(Request $request, Tenant $tenant, CashRegister $cashRegister, PlatformAccessPolicy $policy, PlatformAuditLogger $audit): JsonResponse
    {
        abort_unless($cashRegister->tenant_id === $tenant->id, 404);
        abort_unless($policy->canManageTenantCatalog($request->user(), $tenant), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'timezone' => ['required', 'timezone'], 'default_opening_balance' => ['required', 'numeric', 'min:0'], 'carry_forward_balance' => ['required', 'boolean'],
            'auto_open_enabled' => ['required', 'boolean'], 'auto_open_time' => ['nullable', 'date_format:H:i', 'required_if:auto_open_enabled,true'], 'auto_close_enabled' => ['required', 'boolean'], 'auto_close_time' => ['nullable', 'date_format:H:i', 'required_if:auto_close_enabled,true'],
            'close_grace_minutes' => ['required', 'integer', 'min:0', 'max:180'], 'working_days' => ['required', 'array', 'min:1'], 'working_days.*' => ['integer', 'between:1,7'], 'non_working_dates' => ['nullable', 'array'], 'non_working_dates.*' => ['date_format:Y-m-d'],
            'use_official_holidays' => ['required', 'boolean'], 'allow_non_cash_when_closed' => ['required', 'boolean'], 'active' => ['required', 'boolean'],
        ]);
        $cashRegister->forceFill(['name' => $data['name'], 'status' => $data['active'] ? 'active' : 'inactive'])->save();
        CashRegisterSetting::query()->updateOrCreate(['cash_register_id' => $cashRegister->id], [...$data, 'tenant_id' => $tenant->id, 'updated_by' => $request->user()->id]);
        $audit->record($request, 'cash.settings.updated', ['cash_register_id' => $cashRegister->id]);

        return response()->json(['data' => $this->payload($cashRegister->refresh()->load('setting'))]);
    }

    private function payload(CashRegister $register): array
    {
        $setting = $register->setting;

        return ['id' => $register->id, 'name' => $register->name, 'status' => $register->status, 'core_sucursal_id' => $register->core_sucursal_id, 'core_sucursal_code' => $register->core_sucursal_code, 'core_sucursal_name' => $register->core_sucursal_name, 'settings' => ['timezone' => $setting?->timezone ?? 'America/El_Salvador', 'default_opening_balance' => (float) ($setting?->default_opening_balance ?? 0), 'carry_forward_balance' => $setting?->carry_forward_balance ?? true, 'auto_open_enabled' => $setting?->auto_open_enabled ?? false, 'auto_open_time' => $setting?->auto_open_time ? substr($setting->auto_open_time, 0, 5) : '08:00', 'auto_close_enabled' => $setting?->auto_close_enabled ?? false, 'auto_close_time' => $setting?->auto_close_time ? substr($setting->auto_close_time, 0, 5) : '18:00', 'close_grace_minutes' => $setting?->close_grace_minutes ?? 15, 'working_days' => $setting?->working_days ?? [1, 2, 3, 4, 5, 6], 'non_working_dates' => $setting?->non_working_dates ?? [], 'use_official_holidays' => $setting?->use_official_holidays ?? false, 'allow_non_cash_when_closed' => $setting?->allow_non_cash_when_closed ?? true, 'active' => $setting?->active ?? true]];
    }
}

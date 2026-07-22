<?php

namespace App\Services\Cash;

use App\Models\CashRegisterSetting;
use App\Models\CashSession;
use App\Models\InternalNotification;
use App\Services\FiscalCalendarClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CashAutomationService
{
    public function __construct(private readonly CashService $cash, private readonly FiscalCalendarClient $calendar) {}

    /** @return array{opened:int,cutoff:int,skipped:int} */
    public function process(): array
    {
        $stats = ['opened' => 0, 'cutoff' => 0, 'skipped' => 0];
        CashRegisterSetting::query()->where('active', true)->with(['register.tenant'])->chunkById(100, function ($settings) use (&$stats): void {
            foreach ($settings as $setting) {
                try {
                    $this->processSetting($setting, $stats);
                } catch (\Throwable $exception) {
                    report($exception);
                    $stats['skipped']++;
                }
            }
        });

        return $stats;
    }

    private function processSetting(CashRegisterSetting $setting, array &$stats): void
    {
        $now = CarbonImmutable::now($setting->timezone ?: 'America/El_Salvador');

        if ($setting->auto_close_enabled && $setting->auto_close_time) {
            CashSession::query()
                ->where('cash_register_id', $setting->cash_register_id)
                ->where('status', 'open')
                ->orderBy('business_date')
                ->each(function (CashSession $session) use ($setting, $now, &$stats): void {
                    $cutoff = CarbonImmutable::parse(
                        $session->business_date->format('Y-m-d').' '.$setting->auto_close_time,
                        $setting->timezone ?: 'America/El_Salvador',
                    )->addMinutes($setting->close_grace_minutes);

                    if ($now->greaterThanOrEqualTo($cutoff)) {
                        $this->autoCutoff($setting, $session, $now);
                        $stats['cutoff']++;
                    }
                });
        }

        if (! $this->isWorkingDay($setting, $now)) {
            $stats['skipped']++;

            return;
        }
        $session = CashSession::query()->where('cash_register_id', $setting->cash_register_id)->whereDate('business_date', $now->toDateString())->first();
        if ($setting->auto_open_enabled && ! $session && $setting->auto_open_time && $now->format('H:i:s') >= $setting->auto_open_time) {
            $session = $this->autoOpen($setting, $now);
            $stats['opened']++;
        }
    }

    private function autoOpen(CashRegisterSetting $setting, CarbonImmutable $now): CashSession
    {
        return DB::transaction(function () use ($setting, $now): CashSession {
            $existing = CashSession::query()->where('cash_register_id', $setting->cash_register_id)->whereDate('business_date', $now->toDateString())->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }
            $previous = CashSession::query()->where('cash_register_id', $setting->cash_register_id)->where('business_date', '<', $now->toDateString())->latest('business_date')->first();
            $opening = (float) $setting->default_opening_balance;
            if ($setting->carry_forward_balance && $previous) {
                $opening = (float) ($previous->declared_balance ?? $previous->expected_balance ?? $opening);
            }
            $session = CashSession::query()->create(['tenant_id' => $setting->tenant_id, 'cash_register_id' => $setting->cash_register_id, 'business_date' => $now->toDateString(), 'opening_balance' => $opening, 'opening_source' => $previous && $setting->carry_forward_balance ? 'carry_forward' : 'scheduled', 'status' => 'open', 'count_status' => 'pending', 'opened_at' => $now->utc()]);
            $this->notify($setting, $session, 'Caja abierta automáticamente', 'La sesión inició con '.number_format($opening, 2).' USD.', 'opened');

            return $session;
        });
    }

    private function autoCutoff(CashRegisterSetting $setting, CashSession $session, CarbonImmutable $now): void
    {
        DB::transaction(function () use ($setting, $session, $now): void {
            $locked = CashSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'open') {
                return;
            }
            $totals = $this->cash->sessionTotals($locked);
            $locked->forceFill(['status' => 'closed_unverified', 'count_status' => 'pending_count', 'expected_balance' => $totals['expected'], 'closed_at' => $now->utc(), 'closing_notes' => 'Corte automático programado.'])->save();
            $this->notify($setting, $locked, 'Caja pendiente de conteo', 'El corte esperaba '.number_format($totals['expected'], 2).' USD. Confirma el efectivo contado.', 'cutoff');
        });
    }

    private function isWorkingDay(CashRegisterSetting $setting, CarbonImmutable $date): bool
    {
        if (! in_array($date->dayOfWeekIso, $setting->working_days ?? [1, 2, 3, 4, 5, 6], true)) {
            return false;
        }
        if (in_array($date->toDateString(), $setting->non_working_dates ?? [], true)) {
            return false;
        }
        if ($setting->use_official_holidays) {
            try {
                return ! in_array($date->toDateString(), $this->calendar->publishedHolidays($date->year), true);
            } catch (\Throwable) {
                return true;
            }
        }

        return true;
    }

    private function notify(CashRegisterSetting $setting, CashSession $session, string $title, string $message, string $event): void
    {
        $setting->register->tenant->memberships()->where('status', 'active')->pluck('user_id')->each(function ($userId) use ($setting, $session, $title, $message, $event): void {
            InternalNotification::query()->firstOrCreate(['dedupe_key' => "cash:{$event}:{$session->id}:{$userId}"], ['user_id' => $userId, 'tenant_id' => $setting->tenant_id, 'category' => 'cash', 'title' => $title, 'message' => $setting->register->name.' · '.$message, 'action_url' => '/caja', 'source_type' => 'cash_session', 'source_id' => (string) $session->id]);
        });
    }
}

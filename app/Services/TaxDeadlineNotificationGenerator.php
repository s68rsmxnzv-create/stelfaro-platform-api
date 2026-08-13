<?php

namespace App\Services;

use App\Models\InternalNotification;
use App\Models\UserTenantMembership;
use Carbon\CarbonImmutable;

class TaxDeadlineNotificationGenerator
{
    public function __construct(
        private readonly FiscalCalendarClient $calendar,
    ) {}

    public function generate(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::now('America/El_Salvador')->startOfDay();
        $deadlines = collect([
            ...$this->calendar->publishedDeadlines($today->year),
            ...$this->calendar->publishedDeadlines($today->addYear()->year),
        ])->unique(fn (array $entry): string => (string) ($entry['id'] ?? '').'|'.(string) ($entry['date'] ?? ''));
        $created = 0;

        foreach ($deadlines as $entry) {
            $dueDate = $this->date($entry['date'] ?? null);
            if (! $dueDate || $dueDate->lt($today)) {
                continue;
            }
            if ($dueDate->year !== $today->year || $dueDate->month !== $today->month) {
                continue;
            }

            $days = (int) $today->diffInDays($dueDate, false);
            $stage = $this->stageFor($days);
            if (! $stage) {
                continue;
            }

            UserTenantMembership::query()
                ->with(['user:id', 'tenant:id,status'])
                ->where('status', 'active')
                ->whereHas('tenant', fn ($query) => $query->where('status', 'active'))
                ->chunkById(200, function ($memberships) use ($entry, $dueDate, $days, $stage, &$created): void {
                    foreach ($memberships as $membership) {
                        $sourceId = (string) ($entry['id'] ?? $dueDate->format('Y-m-d'));
                        $dedupeKey = implode(':', ['tax-deadline', $membership->user_id, $membership->tenant_id, $sourceId, $dueDate->format('Y-m-d'), $stage]);
                        $notification = InternalNotification::query()->firstOrCreate(
                            ['dedupe_key' => $dedupeKey],
                            [
                                'user_id' => $membership->user_id,
                                'tenant_id' => $membership->tenant_id,
                                'category' => 'tax_deadline',
                                'title' => $this->title($entry, $stage),
                                'message' => $this->message($entry, $dueDate, $days),
                                'due_date' => $dueDate->format('Y-m-d'),
                                'source_type' => 'fiscal_calendar_entry',
                                'source_id' => $sourceId,
                                'metadata' => [
                                    'form_code' => $entry['form_code'] ?? null,
                                    'stage' => $stage,
                                    'days_remaining' => $days,
                                    'applicability' => $entry['applicability'] ?? null,
                                ],
                            ],
                        );

                        if ($notification->wasRecentlyCreated) {
                            $created++;
                        }
                    }
                });
        }

        return $created;
    }

    private function stageFor(int $days): ?string
    {
        return match (true) {
            $days === 0 => 'due',
            $days === 1 => 'tomorrow',
            $days <= 7 => 'soon',
            $days <= 45 => 'upcoming',
            default => null,
        };
    }

    /** @param array<string, mixed> $entry */
    private function title(array $entry, string $stage): string
    {
        $form = filled($entry['form_code'] ?? null) ? ' '.trim((string) $entry['form_code']) : '';

        return match ($stage) {
            'due' => 'Vence hoy'.$form,
            'tomorrow' => 'Vence mañana'.$form,
            'soon' => 'Vencimiento cercano'.$form,
            default => 'Próximo vencimiento'.$form,
        };
    }

    /** @param array<string, mixed> $entry */
    private function message(array $entry, CarbonImmutable $dueDate, int $days): string
    {
        $activity = trim((string) ($entry['name'] ?? 'Obligación tributaria'));
        $date = $dueDate->locale('es')->isoFormat('dddd D [de] MMMM');
        $remaining = match ($days) {
            0 => 'La fecha límite es hoy.',
            1 => 'Falta 1 día.',
            default => "Faltan {$days} días.",
        };

        return "{$activity}: {$date}. {$remaining}";
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d', $value, 'America/El_Salvador')?->startOfDay();
    }
}

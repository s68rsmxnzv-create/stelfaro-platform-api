<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class FiscalCalendarClient
{
    public function __construct(
        private readonly CoreBillingSessionBroker $broker,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function publishedDeadlines(int $year): array
    {
        $baseUrl = rtrim((string) config('services.dte_core.base_url'), '/');
        abort_if($baseUrl === '', 503, 'La conexión con el calendario fiscal no está configurada.');

        $session = $this->broker->openBackoffice();
        $token = (string) ($session['token'] ?? '');

        if ($token === '') {
            throw new RuntimeException('No fue posible consultar el calendario fiscal.');
        }

        $response = Http::acceptJson()
            ->withToken($token)
            ->timeout(20)
            ->get($baseUrl.'/admin/fiscal-calendars', ['year' => $year]);

        if ($response->failed()) {
            throw new RuntimeException('No fue posible consultar el calendario fiscal.');
        }

        return collect($response->json('data', []))
            ->filter(fn ($calendar): bool => is_array($calendar) && ($calendar['status'] ?? null) === 'published')
            ->flatMap(fn (array $calendar) => $calendar['entries'] ?? [])
            ->filter(fn ($entry): bool => is_array($entry)
                && ($entry['type'] ?? null) === 'declaration_deadline'
                && ($entry['active'] ?? false) === true)
            ->values()
            ->all();
    }
}

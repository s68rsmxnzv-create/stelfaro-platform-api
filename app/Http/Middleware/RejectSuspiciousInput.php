<?php

namespace App\Http\Middleware;

use App\Models\SecurityEvent;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RejectSuspiciousInput
{
    /**
     * @var array<int, string>
     */
    private array $patterns = [
        '/<\s*script\b/i',
        '/<\/\s*script\s*>/i',
        '/\bjavascript\s*:/i',
        '/\bdata\s*:\s*text\/html/i',
        '/\bon(?:abort|blur|click|error|focus|load|mouseover|submit)\s*=/i',
        '/<\s*iframe\b/i',
        '/<\s*object\b/i',
        '/<\s*embed\b/i',
        '/<\s*svg\b[^>]*\bonload\s*=/i',
        '/\bsrcdoc\s*=/i',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('security.suspicious_input.enabled', true) || $this->isExcepted($request)) {
            return $next($request);
        }

        $match = $this->firstSuspiciousValue($request->query->all())
            ?? $this->firstSuspiciousValue($request->request->all());

        if ($match === null) {
            return $next($request);
        }

        $event = $this->recordSecurityEvent($request, $match);
        $payload = [
            'message' => config('security.suspicious_input.message'),
            'details' => config('security.suspicious_input.details'),
            'field' => $match['path'],
            'event_id' => $event?->id,
        ];

        if ($request->expectsJson() || $request->is('api/*') || $request->is('platform-api/*')) {
            return response()->json($payload, 422);
        }

        return response($this->blockedHtml($payload), 422)
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    private function isExcepted(Request $request): bool
    {
        foreach (config('security.suspicious_input.except', []) as $except) {
            if ($request->is($except)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array{path: string, value: string}|null
     */
    private function firstSuspiciousValue(array $values): ?array
    {
        foreach (Arr::dot($values) as $path => $value) {
            if (! is_string($value)) {
                continue;
            }

            foreach ($this->patterns as $pattern) {
                if (preg_match($pattern, $value) === 1) {
                    return [
                        'path' => (string) $path,
                        'value' => $value,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * @param  array{path: string, value: string}  $match
     */
    private function recordSecurityEvent(Request $request, array $match): ?SecurityEvent
    {
        $fingerprint = hash('sha256', implode('|', [
            $request->ip() ?? '',
            $request->userAgent() ?? '',
            $request->method(),
            $request->fullUrl(),
            $match['path'],
            Str::limit($match['value'], 500, ''),
        ]));

        $attributes = [
            'user_id' => $request->user()?->id,
            'type' => 'suspicious_input',
            'severity' => 'high',
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
            'method' => $request->method(),
            'url' => Str::limit($request->fullUrl(), 2000, ''),
            'field' => $match['path'],
            'fingerprint' => $fingerprint,
            'metadata' => [
                'input_sample' => Str::limit($match['value'], 180, ''),
                'referer' => $request->headers->get('referer'),
                'accept' => $request->headers->get('accept'),
            ],
        ];

        try {
            return SecurityEvent::query()->create($attributes);
        } catch (\Throwable $exception) {
            Log::warning('Unable to persist suspicious input security event.', [
                ...$attributes,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array{message: mixed, details: mixed, field: mixed, event_id: mixed}  $payload
     */
    private function blockedHtml(array $payload): string
    {
        $message = e((string) $payload['message']);
        $details = e((string) $payload['details']);
        $eventId = $payload['event_id'] ? e((string) $payload['event_id']) : 'registrado';

        return <<<HTML
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Intento bloqueado | Stelfaro</title>
    <style>
        :root { color-scheme: light dark; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: Canvas; color: CanvasText; padding: 24px; }
        main { width: min(92vw, 640px); border: 1px solid color-mix(in srgb, CanvasText 14%, transparent); border-radius: 14px; padding: 32px; box-shadow: 0 18px 70px color-mix(in srgb, CanvasText 10%, transparent); }
        .badge { display: inline-flex; border-radius: 999px; background: #fee2e2; color: #b91c1c; padding: 8px 12px; font-size: 13px; font-weight: 800; }
        h1 { margin: 18px 0 12px; font-size: clamp(30px, 7vw, 46px); line-height: 1; letter-spacing: 0; }
        p { margin: 0; color: color-mix(in srgb, CanvasText 72%, transparent); font-size: 16px; line-height: 1.7; }
        .trace { margin-top: 22px; border-radius: 10px; background: color-mix(in srgb, CanvasText 7%, transparent); padding: 14px; font-size: 13px; font-weight: 700; color: color-mix(in srgb, CanvasText 70%, transparent); }
    </style>
</head>
<body>
    <main>
        <span class="badge">Solicitud bloqueada</span>
        <h1>{$message}</h1>
        <p>{$details}</p>
        <div class="trace">Evento de auditoría: {$eventId}</div>
    </main>
</body>
</html>
HTML;
    }
}

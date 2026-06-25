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
            'audit' => $this->auditPreview($request, $event),
        ];

        if ($request->expectsJson() || $request->is('api/*') || $request->is('platform-api/*')) {
            return response()->json($payload, 422);
        }

        return response()
            ->view('security.blocked', $payload, 422)
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
     * @return array{path: string, value: string, pattern: string}|null
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
                        'pattern' => $pattern,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * @return array{ip: string, browser: string, route: string, time: string, fingerprint: string}
     */
    private function auditPreview(Request $request, ?SecurityEvent $event): array
    {
        return [
            'ip' => $this->maskIp($request->ip()),
            'browser' => $this->maskUserAgent($request->userAgent()),
            'route' => $request->method().' /'.ltrim($request->path(), '/'),
            'time' => now()->timezone(config('app.timezone'))->format('Y-m-d H:i:s T'),
            'fingerprint' => $event?->fingerprint
                ? substr($event->fingerprint, 0, 12).'...'
                : 'registrada',
        ];
    }

    private function maskIp(?string $ip): string
    {
        if (! $ip) {
            return 'detectada';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);

            return "{$parts[0]}.{$parts[1]}.{$parts[2]}.xxx";
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = explode(':', $ip);
            $visible = implode(':', array_slice($parts, 0, 3));

            return $visible.':xxxx:xxxx';
        }

        return Str::limit($ip, 8, '').'...';
    }

    private function maskUserAgent(?string $userAgent): string
    {
        $userAgent = trim((string) $userAgent);

        if ($userAgent === '') {
            return 'detectado';
        }

        $normalized = $this->safeAuditText($userAgent);

        return Str::limit($normalized, 42, '...');
    }

    private function safeAuditText(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        $normalized = preg_replace('/\s+/', ' ', $value) ?: $value;

        return str_replace(['<', '>'], ['[', ']'], $normalized);
    }

    /**
     * @param  array{path: string, value: string, pattern: string}  $match
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
            'user_agent' => Str::limit($this->safeAuditText($request->userAgent()), 1000, ''),
            'method' => $request->method(),
            'url' => Str::limit($request->fullUrl(), 2000, ''),
            'field' => $match['path'],
            'fingerprint' => $fingerprint,
            'metadata' => [
                'input_sha256' => hash('sha256', $match['value']),
                'input_length' => strlen($match['value']),
                'pattern' => $match['pattern'],
                'referer' => $this->safeAuditText($request->headers->get('referer')),
                'accept' => $this->safeAuditText($request->headers->get('accept')),
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
}

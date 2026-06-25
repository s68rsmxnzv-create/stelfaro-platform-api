<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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

        $payload = [
            'message' => config('security.suspicious_input.message'),
            'field' => $match['path'],
        ];

        if ($request->expectsJson() || $request->is('api/*') || $request->is('platform-api/*')) {
            return response()->json($payload, 422);
        }

        abort(422, $payload['message']);
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
}

<?php

namespace App\Services;

use App\Models\PlatformAuditLog;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PlatformAuditLogger
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(Request $request, string $action, array $metadata = []): ?PlatformAuditLog
    {
        $attributes = [
            'user_id' => $request->user()?->id,
            'tenant_id' => $this->tenantId($request),
            'action' => Str::limit($action, 120, ''),
            'resource_type' => $this->resourceType($request),
            'resource_id' => $this->resourceId($request),
            'result' => (string) ($metadata['result'] ?? 'success'),
            'status_code' => isset($metadata['status_code']) ? (int) $metadata['status_code'] : null,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit($this->safeText($request->userAgent()), 1000, ''),
            'method' => $request->method(),
            'url' => Str::limit($request->fullUrl(), 2000, ''),
            'request_id' => $this->requestId($request),
            'session_id_hash' => $this->sessionHash($request),
            'metadata' => $this->metadata($request, $metadata),
        ];

        try {
            return PlatformAuditLog::query()->create($attributes);
        } catch (\Throwable $exception) {
            Log::warning('No fue posible registrar auditoria de plataforma.', [
                ...$attributes,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function metadata(Request $request, array $metadata): array
    {
        unset($metadata['result'], $metadata['status_code']);

        return array_filter([
            ...$metadata,
            'route' => $request->route()?->getName(),
            'path' => $request->path(),
            'referer' => $this->safeText($request->headers->get('referer')),
            'input_keys' => $this->safeInputKeys($request),
        ], fn ($value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * @return array<int, string>
     */
    private function safeInputKeys(Request $request): array
    {
        $sensitive = ['password', 'password_confirmation', 'current_password', 'token', 'remember', 'signature'];

        return collect(array_keys($request->except($sensitive)))
            ->map(fn (string $key): string => Str::limit($key, 80, ''))
            ->values()
            ->all();
    }

    private function tenantId(Request $request): ?int
    {
        $routeTenant = $request->route('tenant');
        if ($routeTenant instanceof Tenant) {
            return $routeTenant->id;
        }

        if (is_numeric($routeTenant)) {
            return (int) $routeTenant;
        }

        return null;
    }

    private function resourceType(Request $request): ?string
    {
        foreach ($request->route()?->parameters() ?? [] as $key => $value) {
            if ($key === 'tenant') {
                continue;
            }

            return $value instanceof Model ? $value::class : (string) $key;
        }

        return null;
    }

    private function resourceId(Request $request): ?string
    {
        foreach ($request->route()?->parameters() ?? [] as $key => $value) {
            if ($key === 'tenant') {
                continue;
            }

            return $value instanceof Model
                ? (string) $value->getKey()
                : (is_scalar($value) ? (string) $value : null);
        }

        return null;
    }

    private function requestId(Request $request): ?string
    {
        $requestId = $request->headers->get('X-Request-Id') ?: $request->headers->get('X-Correlation-Id');

        return $requestId ? Str::limit($this->safeText($requestId), 64, '') : null;
    }

    private function sessionHash(Request $request): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }

        $sessionId = $request->session()->getId();

        return $sessionId !== '' ? hash('sha256', $sessionId) : null;
    }

    private function safeText(?string $value): string
    {
        $value = trim((string) $value);
        $normalized = preg_replace('/\s+/', ' ', $value) ?: $value;

        return str_replace(['<', '>'], ['[', ']'], $normalized);
    }
}

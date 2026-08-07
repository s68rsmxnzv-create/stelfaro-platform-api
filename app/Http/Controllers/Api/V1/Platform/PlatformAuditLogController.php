<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformAuditLog;
use App\Models\SecurityEvent;
use App\Models\Tenant;
use App\Services\PlatformAccessPolicy;
use App\Services\PlatformAdminAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformAuditLogController extends Controller
{
    public function index(Request $request, PlatformAdminAccess $adminAccess): JsonResponse
    {
        $adminAccess->authorize($request->user());

        $validated = $request->validate([
            'source' => ['nullable', 'string', 'in:all,platform,security'],
            'q' => ['nullable', 'string', 'max:120'],
            'result' => ['nullable', 'string', 'max:32'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $source = (string) ($validated['source'] ?? 'all');
        $limit = (int) ($validated['limit'] ?? 80);
        $logs = collect();

        if (in_array($source, ['all', 'platform'], true)) {
            $logs = $logs->merge($this->platformLogs($validated, $limit));
        }

        if (in_array($source, ['all', 'security'], true)) {
            $logs = $logs->merge($this->securityLogs($validated, $limit));
        }

        $data = $logs
            ->sortByDesc('created_at')
            ->take($limit)
            ->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'limit' => $limit,
                'total_returned' => $data->count(),
                'source' => $source,
            ],
        ]);
    }

    public function tenant(Request $request, Tenant $tenant, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canViewTenantAudit($request->user(), $tenant), 403);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'result' => ['nullable', 'string', 'max:32'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $limit = (int) ($validated['limit'] ?? 80);
        $data = $this->platformLogs($validated, $limit, $tenant)->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'limit' => $limit,
                'total_returned' => $data->count(),
                'source' => 'platform',
                'tenant_id' => $tenant->id,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function platformLogs(array $filters, int $limit, ?Tenant $tenant = null)
    {
        $query = PlatformAuditLog::query()
            ->with(['user:id,name,email', 'tenant:id,name,slug'])
            ->latest();

        if ($tenant) {
            $query->where('tenant_id', $tenant->id);
        }

        $this->applyDateFilters($query, $filters);

        if (! empty($filters['result'])) {
            $query->where('result', (string) $filters['result']);
        }

        if (! empty($filters['q'])) {
            $term = trim((string) $filters['q']);
            $query->where(function ($sub) use ($term): void {
                $sub->whereLike('action', "%{$term}%")
                    ->orWhereLike('url', "%{$term}%")
                    ->orWhereLike('method', "%{$term}%")
                    ->orWhereLike('resource_type', "%{$term}%")
                    ->orWhereLike('resource_id', "%{$term}%")
                    ->orWhereHas('user', fn ($user) => $user
                        ->whereLike('name', "%{$term}%")
                        ->orWhereLike('email', "%{$term}%"))
                    ->orWhereHas('tenant', fn ($tenant) => $tenant
                        ->whereLike('name', "%{$term}%")
                        ->orWhereLike('slug', "%{$term}%"));
            });
        }

        return $query
            ->limit($limit)
            ->get()
            ->map(fn (PlatformAuditLog $log): array => [
                'id' => 'platform-'.$log->id,
                'source' => 'platform',
                'created_at' => $log->created_at?->toISOString(),
                'action' => $log->action,
                'result' => $log->result,
                'severity' => null,
                'status_code' => $log->status_code,
                'method' => $log->method,
                'url' => $log->url,
                'ip_address' => $log->ip_address,
                'user_agent' => $log->user_agent,
                'resource_type' => $log->resource_type,
                'resource_id' => $log->resource_id,
                'user' => $log->user ? [
                    'id' => $log->user->id,
                    'name' => $log->user->name,
                    'email' => $log->user->email,
                ] : null,
                'tenant' => $log->tenant ? [
                    'id' => $log->tenant->id,
                    'name' => $log->tenant->name,
                    'slug' => $log->tenant->slug,
                ] : null,
                'metadata' => $log->metadata,
            ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function securityLogs(array $filters, int $limit)
    {
        $query = SecurityEvent::query()
            ->with('user:id,name,email')
            ->latest();

        $this->applyDateFilters($query, $filters);

        if (! empty($filters['result'])) {
            $query->where('severity', (string) $filters['result']);
        }

        if (! empty($filters['q'])) {
            $term = trim((string) $filters['q']);
            $query->where(function ($sub) use ($term): void {
                $sub->whereLike('type', "%{$term}%")
                    ->orWhereLike('url', "%{$term}%")
                    ->orWhereLike('method', "%{$term}%")
                    ->orWhereLike('field', "%{$term}%")
                    ->orWhereHas('user', fn ($user) => $user
                        ->whereLike('name', "%{$term}%")
                        ->orWhereLike('email', "%{$term}%"));
            });
        }

        return $query
            ->limit($limit)
            ->get()
            ->map(fn (SecurityEvent $event): array => [
                'id' => 'security-'.$event->id,
                'source' => 'security',
                'created_at' => $event->created_at?->toISOString(),
                'action' => $event->type,
                'result' => null,
                'severity' => $event->severity,
                'status_code' => null,
                'method' => $event->method,
                'url' => $event->url,
                'ip_address' => $event->ip_address,
                'user_agent' => $event->user_agent,
                'resource_type' => null,
                'resource_id' => null,
                'user' => $event->user ? [
                    'id' => $event->user->id,
                    'name' => $event->user->name,
                    'email' => $event->user->email,
                ] : null,
                'tenant' => null,
                'metadata' => $event->metadata,
            ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyDateFilters($query, array $filters): void
    {
        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', (string) $filters['date_from'].' 00:00:00');
        }

        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<=', (string) $filters['date_to'].' 23:59:59');
        }
    }
}

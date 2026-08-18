<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformAuditLog;
use App\Models\SecurityEvent;
use App\Models\Tenant;
use App\Services\PlatformAccessPolicy;
use App\Services\PlatformAdminAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $source = (string) ($validated['source'] ?? 'all');
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 40);

        [$data, $total] = $this->paginateMergedLogs(
            $page,
            $perPage,
            in_array($source, ['all', 'platform'], true) ? $this->platformLogsQuery($validated) : null,
            in_array($source, ['all', 'security'], true) ? $this->securityLogsQuery($validated) : null,
        );

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'source' => $source,
            ],
            'stats' => [
                'platform' => PlatformAuditLog::query()->count(),
                'security' => SecurityEvent::query()->count(),
                'attention' => PlatformAuditLog::query()->where('result', 'failed')->count()
                    + SecurityEvent::query()->whereIn('severity', ['warning', 'high'])->count(),
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
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 40);

        [$data, $total] = $this->paginateMergedLogs(
            $page,
            $perPage,
            $this->platformLogsQuery($validated, $tenant),
            null,
        );

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'source' => 'platform',
                'tenant_id' => $tenant->id,
            ],
        ]);
    }

    /**
     * Combina (y pagina) dos fuentes heterogeneas (PlatformAuditLog +
     * SecurityEvent) que no pueden unirse con un solo UNION de Eloquent.
     * Para no traer toda la tabla a memoria en paginas altas, cada fuente
     * solo trae hasta `page * per_page` filas (ya ordenadas desc), se
     * mezclan, se re-ordenan y se recorta al slice de la pagina pedida. El
     * total real se calcula con COUNT, sin traer las filas.
     *
     * @return array{0: Collection<int, array<string, mixed>>, 1: int}
     */
    private function paginateMergedLogs(int $page, int $perPage, ?Builder $platformQuery, ?Builder $securityQuery): array
    {
        $fetchLimit = $page * $perPage;
        $total = 0;
        $logs = collect();

        if ($platformQuery) {
            $total += (clone $platformQuery)->count();
            $logs = $logs->merge(
                (clone $platformQuery)->latest()->limit($fetchLimit)->get()->map(fn (PlatformAuditLog $log) => $this->platformLogPayload($log))
            );
        }

        if ($securityQuery) {
            $total += (clone $securityQuery)->count();
            $logs = $logs->merge(
                (clone $securityQuery)->latest()->limit($fetchLimit)->get()->map(fn (SecurityEvent $event) => $this->securityLogPayload($event))
            );
        }

        $data = $logs
            ->sortByDesc('created_at')
            ->values()
            ->slice(($page - 1) * $perPage, $perPage)
            ->values();

        return [$data, $total];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function platformLogsQuery(array $filters, ?Tenant $tenant = null): Builder
    {
        $query = PlatformAuditLog::query()
            ->with(['user:id,name,email', 'tenant:id,name,slug']);

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

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function securityLogsQuery(array $filters): Builder
    {
        $query = SecurityEvent::query()->with('user:id,name,email');

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

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function platformLogPayload(PlatformAuditLog $log): array
    {
        return [
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function securityLogPayload(SecurityEvent $event): array
    {
        return [
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
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyDateFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', (string) $filters['date_from'].' 00:00:00');
        }

        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<=', (string) $filters['date_to'].' 23:59:59');
        }
    }
}

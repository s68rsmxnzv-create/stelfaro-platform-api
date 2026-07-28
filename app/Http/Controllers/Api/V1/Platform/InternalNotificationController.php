<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Support\Platform\PortalUrl;
use App\Http\Controllers\Controller;
use App\Models\InternalNotification;
use App\Models\User;
use App\Services\PlatformAccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InternalNotificationController extends Controller
{
    public function index(Request $request, PlatformAccessPolicy $policy): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'scope' => ['nullable', 'string', 'in:tenant,admin'],
            'category' => ['nullable', 'string', 'max:60'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $user = $request->user();
        $query = $this->inboxQuery($user, $validated, $policy);

        return response()->json([
            'data' => (clone $query)->latest()->limit((int) ($validated['limit'] ?? 20))->get()->map(fn (InternalNotification $notification): array => $this->data($notification))->values(),
            'unread_count' => (clone $query)->whereNull('read_at')->count(),
        ]);
    }

    public function read(Request $request, InternalNotification $notification, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless((int) $notification->user_id === (int) $request->user()?->id, 404);
        $this->authorizeNotification($request->user(), $notification, $policy);

        if (! $notification->read_at) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return response()->json(['data' => $this->data($notification->refresh())]);
    }

    public function readAll(Request $request, PlatformAccessPolicy $policy): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'scope' => ['nullable', 'string', 'in:tenant,admin'],
            'category' => ['nullable', 'string', 'max:60'],
        ]);

        $this->inboxQuery($request->user(), $validated, $policy)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);

        return response()->json(['unread_count' => 0]);
    }

    public function destroy(Request $request, InternalNotification $notification, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless((int) $notification->user_id === (int) $request->user()?->id, 404);
        $this->authorizeNotification($request->user(), $notification, $policy);
        $notification->delete();

        return response()->json(['message' => 'Notificación eliminada.']);
    }

    /** @param array<string, mixed> $filters */
    private function inboxQuery(User $user, array $filters, PlatformAccessPolicy $policy)
    {
        if (($filters['scope'] ?? 'tenant') === 'admin') {
            abort_unless($policy->canManageTenantRequests($user), 403);

            $query = InternalNotification::query()->where('user_id', $user->id);
            if (filled($filters['category'] ?? null)) {
                $query->where('category', $filters['category']);
            }

            return $query;
        }

        abort_unless(filled($filters['tenant_id'] ?? null), 422, 'Selecciona una empresa.');
        $tenantId = (int) $filters['tenant_id'];
        $this->authorizeTenant($user, $tenantId);

        $query = InternalNotification::query()
            ->where('user_id', $user->id)
            ->where('tenant_id', $tenantId);
        if (filled($filters['category'] ?? null)) {
            $query->where('category', $filters['category']);
        }

        return $query;
    }

    private function authorizeNotification(User $user, InternalNotification $notification, PlatformAccessPolicy $policy): void
    {
        if ($policy->canManageTenantRequests($user)) {
            return;
        }

        $this->authorizeTenant($user, (int) $notification->tenant_id);
    }

    private function authorizeTenant(User $user, int $tenantId): void
    {
        abort_unless($user->memberships()->where('tenant_id', $tenantId)->where('status', 'active')->exists(), 403, 'No tienes acceso a esta empresa.');
    }

    /** @return array<string, mixed> */
    private function data(InternalNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'category' => $notification->category,
            'title' => $notification->title,
            'message' => $notification->message,
            'action_url' => $this->actionUrl($notification),
            'due_date' => $notification->due_date?->format('Y-m-d'),
            'metadata' => $notification->metadata,
            'read_at' => optional($notification->read_at)->toISOString(),
            'created_at' => optional($notification->created_at)->toISOString(),
        ];
    }

    private function actionUrl(InternalNotification $notification): ?string
    {
        $url = $notification->action_url;
        if ($notification->category === 'tenant_request' && is_string($url) && str_starts_with($url, '/requests')) {
            return PortalUrl::app('admin', $url);
        }

        return $url;
    }
}

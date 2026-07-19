<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\InternalNotification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InternalNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $user = $request->user();
        $tenantId = (int) $validated['tenant_id'];
        $this->authorizeTenant($user, $tenantId);

        $query = InternalNotification::query()
            ->where('user_id', $user->id)
            ->where('tenant_id', $tenantId);

        return response()->json([
            'data' => (clone $query)->latest()->limit((int) ($validated['limit'] ?? 20))->get()->map(fn (InternalNotification $notification): array => $this->data($notification))->values(),
            'unread_count' => (clone $query)->whereNull('read_at')->count(),
        ]);
    }

    public function read(Request $request, InternalNotification $notification): JsonResponse
    {
        abort_unless((int) $notification->user_id === (int) $request->user()?->id, 404);
        $this->authorizeTenant($request->user(), (int) $notification->tenant_id);

        if (! $notification->read_at) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return response()->json(['data' => $this->data($notification->refresh())]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $validated = $request->validate(['tenant_id' => ['required', 'integer', 'exists:tenants,id']]);
        $tenantId = (int) $validated['tenant_id'];
        $this->authorizeTenant($request->user(), $tenantId);

        InternalNotification::query()
            ->where('user_id', $request->user()->id)
            ->where('tenant_id', $tenantId)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);

        return response()->json(['unread_count' => 0]);
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
            'action_url' => $notification->action_url,
            'due_date' => $notification->due_date?->format('Y-m-d'),
            'metadata' => $notification->metadata,
            'read_at' => optional($notification->read_at)->toISOString(),
            'created_at' => optional($notification->created_at)->toISOString(),
        ];
    }
}

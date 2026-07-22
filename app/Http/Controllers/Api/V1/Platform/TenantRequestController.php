<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\InternalNotification;
use App\Models\Tenant;
use App\Models\TenantRequest;
use App\Models\User;
use App\Services\PlatformAccessPolicy;
use App\Support\Platform\PlatformRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TenantRequestController extends Controller
{
    public function index(Request $request, Tenant $tenant, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canViewTenantRequests($request->user(), $tenant), 403);
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(TenantRequest::STATUSES)],
            'type' => ['nullable', 'string', Rule::in(TenantRequest::TYPES)],
        ]);

        $query = $tenant->requests()->with(['requester:id,name,email', 'assignee:id,name,email', 'fulfilledUser:id,name,email,must_change_password'])->latest();
        if (filled($validated['status'] ?? null)) {
            $query->where('status', $validated['status']);
        }
        if (filled($validated['type'] ?? null)) {
            $query->where('type', $validated['type']);
        }

        return response()->json(['data' => $query->limit(100)->get()->map(fn (TenantRequest $item): array => $this->payload($item))->values()]);
    }

    public function revealCredentials(Request $request, Tenant $tenant, TenantRequest $tenantRequest, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($tenantRequest->tenant_id === $tenant->id, 404);
        abort_unless($policy->canViewTenantRequests($request->user(), $tenant), 403);
        abort_unless($tenantRequest->requested_by_user_id === $request->user()->id, 403);
        abort_if(blank($tenantRequest->temporary_password), 404, 'Esta solicitud no tiene una contraseña temporal disponible.');
        abort_if($tenantRequest->fulfilledUser && ! $tenantRequest->fulfilledUser->must_change_password, 410, 'La contraseña temporal ya no está vigente.');

        $tenantRequest->forceFill(['credentials_revealed_at' => now()])->save();

        return response()->json(['data' => [
            'email' => $tenantRequest->fulfilledUser?->email,
            'temporary_password' => $tenantRequest->temporary_password,
        ]]);
    }

    public function store(Request $request, Tenant $tenant, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canCreateTenantRequest($request->user(), $tenant), 403);
        $validated = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'type' => ['required', 'string', Rule::in(TenantRequest::TYPES)],
            'subject' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:5000'],
            'payload' => ['nullable', 'array'],
            'payload.name' => ['nullable', 'string', 'max:255'],
            'payload.email' => ['nullable', 'email', 'max:255'],
            'payload.phone' => ['nullable', 'string', 'max:40'],
            'payload.role' => ['nullable', 'string', 'max:40'],
            'payload.address' => ['nullable', 'string', 'max:1000'],
            'payload.code' => ['nullable', 'string', 'max:40'],
            'payload.action' => ['nullable', 'string', 'max:60'],
        ]);

        $item = $tenant->requests()->firstOrCreate(
            [
                'requested_by_user_id' => $request->user()->id,
                'idempotency_key' => $validated['idempotency_key'],
            ],
            [
                'public_id' => (string) Str::uuid(),
                'type' => $validated['type'],
                'status' => 'pending',
                'subject' => trim($validated['subject']),
                'description' => filled($validated['description'] ?? null) ? trim((string) $validated['description']) : null,
                'payload' => $validated['payload'] ?? null,
            ],
        );

        if ($item->wasRecentlyCreated) {
            $this->notifyPlatformAdmins($item->loadMissing(['tenant', 'requester']));
        }

        return response()->json(
            ['data' => $this->payload($item->load(['requester', 'assignee']))],
            $item->wasRecentlyCreated ? 201 : 200,
        );
    }

    private function notifyPlatformAdmins(TenantRequest $item): void
    {
        $bootstrapEmails = array_values(array_unique([
            ...config('platform.admin.platform_emails', []),
            ...config('platform.admin.emails', []),
        ]));
        $admins = User::query()
            ->where(function ($query) use ($bootstrapEmails): void {
                $query->whereIn('platform_role', PlatformRoles::globalAdminRoles());
                if ($bootstrapEmails !== []) {
                    $query->orWhereIn('email', $bootstrapEmails);
                }
            })
            ->where('id', '!=', $item->requested_by_user_id)
            ->get(['id']);

        foreach ($admins as $admin) {
            InternalNotification::query()->firstOrCreate(
                ['dedupe_key' => 'tenant-request-created:'.$item->id.':'.$admin->id],
                [
                    'user_id' => $admin->id,
                    'tenant_id' => $item->tenant_id,
                    'category' => 'tenant_request',
                    'title' => 'Nueva solicitud '.self::reference($item),
                    'message' => ($item->tenant?->name ?? 'Una empresa').' solicita: '.$item->subject.'.',
                    'action_url' => 'https://'.config('platform.hosts.admin').'/requests?request='.$item->id,
                    'source_type' => TenantRequest::class,
                    'source_id' => $item->id,
                    'metadata' => ['request_id' => $item->public_id, 'request_reference' => self::reference($item), 'status' => $item->status],
                ],
            );
        }
    }

    private static function reference(TenantRequest $item): string
    {
        return 'SOL-'.str_pad((string) $item->id, 6, '0', STR_PAD_LEFT);
    }

    /** @return array<string, mixed> */
    public static function payload(TenantRequest $item): array
    {
        return [
            'id' => $item->id,
            'public_id' => $item->public_id,
            'reference' => self::reference($item),
            'tenant' => $item->relationLoaded('tenant') && $item->tenant ? ['id' => $item->tenant->id, 'name' => $item->tenant->name] : ['id' => $item->tenant_id],
            'requester' => $item->requester ? ['id' => $item->requester->id, 'name' => $item->requester->name, 'email' => $item->requester->email] : null,
            'assignee' => $item->assignee ? ['id' => $item->assignee->id, 'name' => $item->assignee->name, 'email' => $item->assignee->email] : null,
            'type' => $item->type,
            'status' => $item->status,
            'subject' => $item->subject,
            'description' => $item->description,
            'payload' => $item->payload,
            'admin_response' => $item->admin_response,
            'fulfillment' => $item->fulfilledUser ? [
                'user' => ['id' => $item->fulfilledUser->id, 'name' => $item->fulfilledUser->name, 'email' => $item->fulfilledUser->email],
                'credentials_available' => filled($item->temporary_password) && (bool) $item->fulfilledUser->must_change_password,
                'credentials_revealed_at' => optional($item->credentials_revealed_at)->toISOString(),
            ] : null,
            'reviewed_at' => optional($item->reviewed_at)->toISOString(),
            'completed_at' => optional($item->completed_at)->toISOString(),
            'created_at' => optional($item->created_at)->toISOString(),
            'updated_at' => optional($item->updated_at)->toISOString(),
        ];
    }
}

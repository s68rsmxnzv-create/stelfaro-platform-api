<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantRequest;
use App\Services\PlatformAccessPolicy;
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

        $query = $tenant->requests()->with(['requester:id,name,email', 'assignee:id,name,email'])->latest();
        if (filled($validated['status'] ?? null)) {
            $query->where('status', $validated['status']);
        }
        if (filled($validated['type'] ?? null)) {
            $query->where('type', $validated['type']);
        }

        return response()->json(['data' => $query->limit(100)->get()->map(fn (TenantRequest $item): array => $this->payload($item))->values()]);
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

        return response()->json(
            ['data' => $this->payload($item->load(['requester', 'assignee']))],
            $item->wasRecentlyCreated ? 201 : 200,
        );
    }

    /** @return array<string, mixed> */
    public static function payload(TenantRequest $item): array
    {
        return [
            'id' => $item->id,
            'public_id' => $item->public_id,
            'reference' => 'SOL-'.str_pad((string) $item->id, 6, '0', STR_PAD_LEFT),
            'tenant' => $item->relationLoaded('tenant') && $item->tenant ? ['id' => $item->tenant->id, 'name' => $item->tenant->name] : ['id' => $item->tenant_id],
            'requester' => $item->requester ? ['id' => $item->requester->id, 'name' => $item->requester->name, 'email' => $item->requester->email] : null,
            'assignee' => $item->assignee ? ['id' => $item->assignee->id, 'name' => $item->assignee->name, 'email' => $item->assignee->email] : null,
            'type' => $item->type,
            'status' => $item->status,
            'subject' => $item->subject,
            'description' => $item->description,
            'payload' => $item->payload,
            'admin_response' => $item->admin_response,
            'reviewed_at' => optional($item->reviewed_at)->toISOString(),
            'completed_at' => optional($item->completed_at)->toISOString(),
            'created_at' => optional($item->created_at)->toISOString(),
            'updated_at' => optional($item->updated_at)->toISOString(),
        ];
    }
}

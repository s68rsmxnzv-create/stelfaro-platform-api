<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\InternalNotification;
use App\Models\TenantRequest;
use App\Services\PlatformAccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminTenantRequestController extends Controller
{
    public function index(Request $request, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canManageTenantRequests($request->user()), 403);
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(TenantRequest::STATUSES)],
            'type' => ['nullable', 'string', Rule::in(TenantRequest::TYPES)],
            'q' => ['nullable', 'string', 'max:120'],
        ]);
        $query = TenantRequest::query()->with(['tenant:id,name', 'requester:id,name,email', 'assignee:id,name,email'])->latest();
        if (filled($validated['status'] ?? null)) {
            $query->where('status', $validated['status']);
        }
        if (filled($validated['type'] ?? null)) {
            $query->where('type', $validated['type']);
        }
        if (filled($validated['q'] ?? null)) {
            $term = trim((string) $validated['q']);
            $query->where(function ($builder) use ($term): void {
                $builder->where('subject', 'like', "%{$term}%")
                    ->orWhereHas('tenant', fn ($tenant) => $tenant->where('name', 'like', "%{$term}%"))
                    ->orWhereHas('requester', fn ($user) => $user->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%"));
            });
        }

        return response()->json(['data' => $query->limit(200)->get()->map(fn (TenantRequest $item): array => TenantRequestController::payload($item))->values()]);
    }

    public function update(Request $request, TenantRequest $tenantRequest, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canManageTenantRequests($request->user()), 403);
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(TenantRequest::STATUSES)],
            'admin_response' => ['nullable', 'string', 'max:5000'],
        ]);
        abort_if($validated['status'] === 'needs_information' && blank($validated['admin_response'] ?? null), 422, 'Indica qué información hace falta.');
        abort_if(in_array($validated['status'], ['rejected', 'completed'], true) && blank($validated['admin_response'] ?? null), 422, 'Agrega una respuesta para cerrar la solicitud.');

        $previousStatus = $tenantRequest->status;
        $tenantRequest->forceFill([
            'status' => $validated['status'],
            'admin_response' => filled($validated['admin_response'] ?? null) ? trim((string) $validated['admin_response']) : null,
            'assigned_to_user_id' => $request->user()->id,
            'reviewed_at' => $tenantRequest->reviewed_at ?? now(),
            'completed_at' => in_array($validated['status'], ['completed', 'rejected', 'cancelled'], true) ? now() : null,
        ])->save();

        if ($previousStatus !== $tenantRequest->status || filled($tenantRequest->admin_response)) {
            $dedupeKey = 'tenant-request:'.$tenantRequest->id.':'.$tenantRequest->status.':'.$tenantRequest->updated_at?->getTimestamp();
            InternalNotification::query()->firstOrCreate(
                ['dedupe_key' => $dedupeKey],
                [
                    'user_id' => $tenantRequest->requested_by_user_id,
                    'tenant_id' => $tenantRequest->tenant_id,
                    'category' => 'tenant_request',
                    'title' => 'Solicitud actualizada',
                    'message' => $this->statusMessage($tenantRequest),
                    'action_url' => '/configuracion?view=requests',
                    'source_type' => TenantRequest::class,
                    'source_id' => $tenantRequest->id,
                    'metadata' => ['request_id' => $tenantRequest->public_id, 'status' => $tenantRequest->status],
                ],
            );
        }

        return response()->json(['data' => TenantRequestController::payload($tenantRequest->load(['tenant', 'requester', 'assignee']))]);
    }

    private function statusMessage(TenantRequest $item): string
    {
        $labels = [
            'pending' => 'permanece pendiente',
            'in_review' => 'está en revisión',
            'needs_information' => 'necesita información adicional',
            'approved' => 'fue aprobada',
            'completed' => 'fue completada',
            'rejected' => 'fue rechazada',
            'cancelled' => 'fue cancelada',
        ];

        return "{$item->subject}: ".($labels[$item->status] ?? 'fue actualizada').'.';
    }
}

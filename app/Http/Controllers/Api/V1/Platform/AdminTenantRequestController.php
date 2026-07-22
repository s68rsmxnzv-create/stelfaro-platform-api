<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\InternalNotification;
use App\Models\TenantRequest;
use App\Services\Platform\DirectTenantUserService;
use App\Services\Platform\TemporaryPasswordNotificationClient;
use App\Services\Platform\TenantEnvironmentResolver;
use App\Services\PlatformAccessPolicy;
use App\Support\Platform\PlatformRoles;
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
        $query = TenantRequest::query()->with(['tenant:id,name,slug', 'requester:id,name,email', 'assignee:id,name,email', 'fulfilledUser:id,name,email,must_change_password'])->latest();
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

    public function createUser(
        Request $request,
        TenantRequest $tenantRequest,
        PlatformAccessPolicy $policy,
        DirectTenantUserService $users,
        TenantEnvironmentResolver $environmentResolver,
        TemporaryPasswordNotificationClient $temporaryPasswords,
    ): JsonResponse {
        abort_unless($policy->canManageTenantRequests($request->user()), 403);
        abort_unless($tenantRequest->type === 'user_access' && data_get($tenantRequest->payload, 'action') === 'create', 422, 'Esta solicitud no corresponde a la creación de un usuario.');
        abort_if($tenantRequest->fulfilled_user_id, 422, 'Esta solicitud ya fue atendida.');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'role' => ['required', 'string', Rule::in([PlatformRoles::COMPANY_ADMIN, PlatformRoles::BILLING_ADMIN, PlatformRoles::BILLING_USER, PlatformRoles::VIEWER])],
        ]);

        $tenant = $tenantRequest->tenant()->firstOrFail();
        $result = $users->create($tenant, $validated['name'], $validated['email'], $validated['role'], $request->user(), phone: $validated['phone'] ?? null);
        $delivery = null;
        if ($environmentResolver->isProduction($tenant) && $result['created'] && $result['temporary_password']) {
            $delivery = $temporaryPasswords->send($tenant, $result['user'], $validated['role'], $result['temporary_password'], 'tenant_request');
        }

        $tenantRequest->forceFill([
            'status' => 'completed',
            'admin_response' => $tenantRequest->admin_response ?: 'El usuario fue creado y su acceso quedó habilitado.',
            'assigned_to_user_id' => $request->user()->id,
            'fulfilled_user_id' => $result['user']->id,
            'temporary_password' => $result['temporary_password'],
            'credentials_available_at' => $result['temporary_password'] ? now() : null,
            'reviewed_at' => $tenantRequest->reviewed_at ?? now(),
            'completed_at' => now(),
        ])->save();

        InternalNotification::query()->create([
            'user_id' => $tenantRequest->requested_by_user_id,
            'tenant_id' => $tenantRequest->tenant_id,
            'category' => 'tenant_request',
            'title' => $result['temporary_password'] ? 'Usuario creado · credenciales disponibles' : 'Acceso de usuario habilitado',
            'message' => $tenantRequest->subject.' fue completada.',
            'action_url' => '/configuracion?view=requests&request='.$tenantRequest->id,
            'source_type' => TenantRequest::class,
            'source_id' => $tenantRequest->id,
            'dedupe_key' => 'tenant-request-user-created:'.$tenantRequest->id,
            'metadata' => ['request_id' => $tenantRequest->public_id, 'status' => 'completed', 'credentials_available' => (bool) $result['temporary_password']],
        ]);

        return response()->json([
            'data' => TenantRequestController::payload($tenantRequest->load(['tenant', 'requester', 'assignee', 'fulfilledUser'])),
            'user' => ['id' => $result['user']->id, 'name' => $result['user']->name, 'email' => $result['user']->email],
            'temporary_password' => $result['temporary_password'],
            'temporary_password_delivery' => $delivery,
            'created' => $result['created'],
        ], 201);
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
                    'action_url' => '/configuracion?view=requests&request='.$tenantRequest->id,
                    'source_type' => TenantRequest::class,
                    'source_id' => $tenantRequest->id,
                    'metadata' => ['request_id' => $tenantRequest->public_id, 'request_reference' => 'SOL-'.str_pad((string) $tenantRequest->id, 6, '0', STR_PAD_LEFT), 'status' => $tenantRequest->status],
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

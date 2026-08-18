<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\InternalNotification;
use App\Models\TenantRequest;
use App\Services\CoreFiscalScopeClient;
use App\Services\CoreTenantStructureClient;
use App\Services\Platform\DirectTenantUserService;
use App\Services\Platform\TemporaryPasswordNotificationClient;
use App\Services\Platform\TenantEnvironmentResolver;
use App\Services\PlatformAccessPolicy;
use App\Services\TenantFiscalLinkResolver;
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
            'id' => ['nullable', 'integer', 'min:1'],
        ]);
        $query = TenantRequest::query()->with(['tenant:id,name,slug', 'requester:id,name,email', 'assignee:id,name,email', 'fulfilledUser:id,name,email,must_change_password'])->latest();
        if (filled($validated['id'] ?? null)) {
            // Permite abrir una solicitud puntual (p. ej. desde un enlace de
            // notificacion) sin importar en que pagina caeria normalmente.
            $query->where('id', $validated['id']);
        }
        if (filled($validated['status'] ?? null)) {
            $query->where('status', $validated['status']);
        }
        if (filled($validated['type'] ?? null)) {
            $query->where('type', $validated['type']);
        }
        if (filled($validated['q'] ?? null)) {
            $term = trim((string) $validated['q']);
            $query->where(function ($builder) use ($term): void {
                $builder->whereLike('subject', "%{$term}%")
                    ->orWhereHas('tenant', fn ($tenant) => $tenant->whereLike('name', "%{$term}%"))
                    ->orWhereHas('requester', fn ($user) => $user->whereLike('name', "%{$term}%")->orWhereLike('email', "%{$term}%"));
            });
        }

        $perPage = (int) min(max((int) $request->query('per_page', 25), 1), 100);
        $requests = $query->paginate($perPage);

        return response()->json([
            'data' => collect($requests->items())->map(fn (TenantRequest $item): array => TenantRequestController::payload($item))->values(),
            'meta' => [
                'current_page' => $requests->currentPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
                'last_page' => $requests->lastPage(),
            ],
            'stats' => [
                'pending' => TenantRequest::query()->whereIn('status', ['pending', 'needs_information'])->count(),
                'in_review' => TenantRequest::query()->where('status', 'in_review')->count(),
                'completed' => TenantRequest::query()->where('status', 'completed')->count(),
            ],
        ]);
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
            'fulfilled_resource_type' => 'user',
            'fulfilled_resource_id' => (string) $result['user']->id,
            'reviewed_payload' => $validated,
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

    public function review(Request $request, TenantRequest $tenantRequest, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canManageTenantRequests($request->user()), 403);
        if ($tenantRequest->status === 'pending') {
            $tenantRequest->forceFill([
                'status' => 'in_review',
                'assigned_to_user_id' => $request->user()->id,
                'reviewed_at' => now(),
            ])->save();
        }

        return response()->json(['data' => TenantRequestController::payload($tenantRequest->load(['tenant', 'requester', 'assignee', 'fulfilledUser']))]);
    }

    public function createBranch(Request $request, TenantRequest $tenantRequest, PlatformAccessPolicy $policy, TenantFiscalLinkResolver $links, CoreTenantStructureClient $core): JsonResponse
    {
        abort_unless($policy->canManageTenantRequests($request->user()), 403);
        abort_unless($tenantRequest->type === 'branch', 422, 'Esta solicitud no corresponde a una sucursal.');
        abort_if($tenantRequest->fulfilled_resource_id, 422, 'Esta solicitud ya fue atendida.');
        $validated = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'codigo' => ['required', 'string', 'regex:/^[MBSP][0-9]{3}$/i'],
            'direccion' => ['required', 'string', 'max:255'],
            'departamento' => ['required', 'string', 'max:64'],
            'municipio' => ['required', 'string', 'max:64'],
            'distrito' => ['nullable', 'string', 'max:64'],
            'telefono' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'punto_venta_codigo' => ['required', 'string', 'regex:/^P[0-9]{3}$/i'],
            'punto_venta_nombre' => ['required', 'string', 'max:255'],
            'punto_venta_tipo' => ['nullable', 'string', 'max:64'],
        ]);
        $response = $core->createBranch($links->coreEmpresaId($tenantRequest->tenant), [
            ...$validated,
            'request_reference' => $tenantRequest->public_id,
        ]);
        $empresa = $response['empresa'] ?? [];
        $branch = collect($empresa['sucursales'] ?? [])->firstWhere('codigo', strtoupper($validated['codigo']));
        abort_unless(is_array($branch) && isset($branch['id']), 502, 'La sucursal fue procesada, pero no pudimos identificarla.');

        return $this->completeStructure($request, $tenantRequest, 'branch', (string) $branch['id'], $validated);
    }

    public function createPointOfSale(
        Request $request,
        TenantRequest $tenantRequest,
        PlatformAccessPolicy $policy,
        TenantFiscalLinkResolver $links,
        CoreFiscalScopeClient $scope,
        CoreTenantStructureClient $core,
    ): JsonResponse {
        abort_unless($policy->canManageTenantRequests($request->user()), 403);
        abort_unless($tenantRequest->type === 'point_of_sale', 422, 'Esta solicitud no corresponde a un punto de venta.');
        abort_if($tenantRequest->fulfilled_resource_id, 422, 'Esta solicitud ya fue atendida.');
        $validated = $request->validate([
            'sucursal_id' => ['required', 'integer', 'min:1'],
            'codigo' => ['required', 'string', 'regex:/^P[0-9]{3}$/i'],
            'nombre' => ['required', 'string', 'max:255'],
            'tipo' => ['nullable', 'string', 'max:64'],
        ]);
        $companyScope = $scope->companyScope($links->coreEmpresaId($tenantRequest->tenant));
        $belongsToTenant = collect($companyScope['sucursales'] ?? [])
            ->contains(fn (array $branch): bool => (int) ($branch['id'] ?? 0) === (int) $validated['sucursal_id']);
        abort_unless($belongsToTenant, 422, 'La sucursal seleccionada no pertenece a esta empresa.');

        $response = $core->createPointOfSale((int) $validated['sucursal_id'], [
            'codigo' => $validated['codigo'], 'nombre' => $validated['nombre'], 'tipo' => $validated['tipo'] ?? 'terminal',
            'request_reference' => $tenantRequest->public_id,
        ]);
        $empresa = $response['empresa'] ?? [];
        $branch = collect($empresa['sucursales'] ?? [])->firstWhere('id', (int) $validated['sucursal_id']);
        $point = collect($branch['puntosVenta'] ?? [])->firstWhere('codigo', strtoupper($validated['codigo']));
        abort_unless(is_array($point) && isset($point['id']), 502, 'El punto de venta fue procesado, pero no pudimos identificarlo.');

        return $this->completeStructure($request, $tenantRequest, 'point_of_sale', (string) $point['id'], $validated);
    }

    /** @param array<string, mixed> $reviewed */
    private function completeStructure(Request $request, TenantRequest $tenantRequest, string $type, string $id, array $reviewed): JsonResponse
    {
        $tenantRequest->forceFill([
            'status' => 'completed',
            'admin_response' => $tenantRequest->admin_response ?: 'La solicitud fue aprobada y creada correctamente.',
            'assigned_to_user_id' => $request->user()->id,
            'reviewed_payload' => $reviewed,
            'fulfilled_resource_type' => $type,
            'fulfilled_resource_id' => $id,
            'reviewed_at' => $tenantRequest->reviewed_at ?? now(),
            'completed_at' => now(),
        ])->save();
        InternalNotification::query()->firstOrCreate(['dedupe_key' => 'tenant-request-fulfilled:'.$tenantRequest->id], [
            'user_id' => $tenantRequest->requested_by_user_id, 'tenant_id' => $tenantRequest->tenant_id,
            'category' => 'tenant_request', 'title' => 'Solicitud completada',
            'message' => $tenantRequest->subject.' fue aprobada y creada.',
            'action_url' => '/configuracion?view=requests&request='.$tenantRequest->id,
            'source_type' => TenantRequest::class, 'source_id' => $tenantRequest->id,
            'metadata' => ['request_id' => $tenantRequest->public_id, 'status' => 'completed', 'resource_type' => $type, 'resource_id' => $id],
        ]);

        return response()->json(['data' => TenantRequestController::payload($tenantRequest->load(['tenant', 'requester', 'assignee', 'fulfilledUser']))], 201);
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

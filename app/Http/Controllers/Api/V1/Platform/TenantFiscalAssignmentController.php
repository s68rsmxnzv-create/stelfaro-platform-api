<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\UserFiscalAssignment;
use App\Models\UserTenantMembership;
use App\Services\CoreFiscalScopeClient;
use App\Services\PlatformAccessPolicy;
use App\Services\TenantFiscalLinkResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TenantFiscalAssignmentController extends Controller
{
    public function scope(
        Request $request,
        Tenant $tenant,
        PlatformAccessPolicy $policy,
        TenantFiscalLinkResolver $links,
        CoreFiscalScopeClient $core,
    ): JsonResponse {
        abort_unless($policy->canViewTenantUsers($request->user(), $tenant), 403);

        return response()->json($core->companyScope($links->coreEmpresaId($tenant)));
    }

    public function store(
        Request $request,
        UserTenantMembership $membership,
        PlatformAccessPolicy $policy,
        TenantFiscalLinkResolver $links,
        CoreFiscalScopeClient $core,
    ): JsonResponse {
        $membership->load('tenant');
        abort_unless($policy->canChangeTenantMemberRole($request->user(), $membership->tenant), 403);

        $validated = $request->validate([
            'assignments' => ['present', 'array'],
            'assignments.*.sucursal_id' => ['required', 'integer', 'min:1'],
            'assignments.*.punto_venta_id' => ['required', 'integer', 'min:1'],
            'assignments.*.is_default' => ['sometimes', 'boolean'],
        ]);

        $coreEmpresaId = $links->coreEmpresaId($membership->tenant);
        $this->assertValidFiscalAssignments($validated['assignments'], $core->companyScope($coreEmpresaId));

        DB::transaction(function () use ($membership, $validated, $coreEmpresaId): void {
            $membership->fiscalAssignments()->delete();

            $defaultAssigned = false;

            foreach ($validated['assignments'] as $assignment) {
                $isDefault = (bool) ($assignment['is_default'] ?? false);
                if ($isDefault && $defaultAssigned) {
                    $isDefault = false;
                }
                $defaultAssigned = $defaultAssigned || $isDefault;

                UserFiscalAssignment::query()->create([
                    'membership_id' => $membership->id,
                    'core_empresa_id' => $coreEmpresaId,
                    'core_sucursal_id' => (int) $assignment['sucursal_id'],
                    'core_punto_venta_id' => (int) $assignment['punto_venta_id'],
                    'is_default' => $isDefault,
                    'status' => 'active',
                ]);
            }

            if (! $defaultAssigned) {
                $membership->fiscalAssignments()->oldest('id')->first()?->forceFill(['is_default' => true])->save();
            }
        });

        return response()->json([
            'assignments' => $membership->refresh()->fiscalAssignments()->get()->map(fn (UserFiscalAssignment $assignment): array => [
                'id' => $assignment->id,
                'membership_id' => $assignment->membership_id,
                'core_empresa_id' => $assignment->core_empresa_id,
                'core_sucursal_id' => $assignment->core_sucursal_id,
                'core_punto_venta_id' => $assignment->core_punto_venta_id,
                'is_default' => (bool) $assignment->is_default,
                'status' => $assignment->status,
            ])->values(),
        ]);
    }

    /**
     * @param  array<int, array{sucursal_id: int, punto_venta_id: int, is_default?: bool}>  $assignments
     * @param  array<string, mixed>  $scope
     */
    private function assertValidFiscalAssignments(array $assignments, array $scope): void
    {
        $validPairs = collect($scope['sucursales'] ?? [])
            ->flatMap(fn (array $sucursal): array => collect($sucursal['puntos_venta'] ?? [])
                ->map(fn (array $puntoVenta): string => ((int) ($sucursal['id'] ?? 0)).':'.((int) ($puntoVenta['id'] ?? 0)))
                ->all())
            ->flip();

        foreach ($assignments as $index => $assignment) {
            $key = ((int) $assignment['sucursal_id']).':'.((int) $assignment['punto_venta_id']);

            if (! $validPairs->has($key)) {
                throw ValidationException::withMessages([
                    "assignments.{$index}.punto_venta_id" => 'El punto de venta no pertenece a la sucursal fiscal seleccionada.',
                ]);
            }
        }
    }
}

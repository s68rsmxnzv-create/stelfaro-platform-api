<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\PlatformAccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantLookupController extends Controller
{
    public function byCoreEmpresa(Request $request, int $coreEmpresaId, PlatformAccessPolicy $policy): JsonResponse
    {
        $tenant = Tenant::query()
            ->where('metadata->core_empresa_id', $coreEmpresaId)
            ->first();

        if (! $tenant) {
            return response()->json(['tenant' => null], 404);
        }

        abort_unless($policy->canViewTenantUsers($request->user(), $tenant), 403);

        return response()->json([
            'tenant' => [
                'id' => $tenant->id,
                'slug' => $tenant->slug,
                'name' => $tenant->name,
                'status' => $tenant->status,
                'core_empresa_id' => $coreEmpresaId,
            ],
        ]);
    }
}

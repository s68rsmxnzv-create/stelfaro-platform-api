<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Services\Platform\TenantPurgeService;
use App\Services\PlatformAccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantPurgeController extends Controller
{
    public function destroyByCoreEmpresa(
        Request $request,
        int $coreEmpresaId,
        PlatformAccessPolicy $policy,
        TenantPurgeService $purge,
    ): JsonResponse {
        abort_unless($policy->hasPlatformOwnerRole($request->user()), 403);

        $deleted = $purge->purgeByCoreEmpresaId($coreEmpresaId);

        return response()->json([
            'deleted' => $deleted,
        ]);
    }
}

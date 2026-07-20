<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Inventory\CommercialSummaryService;
use App\Services\PlatformAccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommercialDashboardController extends Controller
{
    public function __invoke(Request $request, Tenant $tenant, PlatformAccessPolicy $policy, CommercialSummaryService $summary): JsonResponse
    {
        abort_unless($policy->canViewTenantCatalog($request->user(), $tenant), 403);

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'commercial' => $summary->totals($tenant),
        ]);
    }
}

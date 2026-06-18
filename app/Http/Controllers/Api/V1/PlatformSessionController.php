<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PlatformAdminAccess;
use App\Services\PlatformSessionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformSessionController extends Controller
{
    public function __invoke(
        Request $request,
        PlatformSessionResolver $resolver,
        PlatformAdminAccess $adminAccess,
    ): JsonResponse
    {
        return response()->json([
            ...$resolver->resolve($request->user()),
            'can_access_platform_admin' => $adminAccess->allows($request->user()),
            'platform_admin_url' => 'https://'.config('platform.hosts.admin'),
        ]);
    }
}

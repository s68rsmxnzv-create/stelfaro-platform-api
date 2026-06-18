<?php

namespace App\Http\Controllers\PlatformAdmin;

use App\Http\Controllers\Controller;
use App\Services\CoreBillingSessionBroker;
use App\Services\PlatformAdminAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CoreSessionController extends Controller
{
    public function __invoke(
        Request $request,
        PlatformAdminAccess $adminAccess,
        CoreBillingSessionBroker $broker,
    ): JsonResponse {
        $adminAccess->authorize($request->user());

        return response()->json([
            'base_url' => 'https://'.config('platform.hosts.platform').'/api/v1/admin/core',
            'session' => $broker->openBackoffice(),
        ]);
    }
}

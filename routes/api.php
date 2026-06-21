<?php

use App\Http\Controllers\Api\V1\Platform\GlobalUserController;
use App\Http\Controllers\Api\V1\Platform\TenantInvitationController;
use App\Http\Controllers\Api\V1\Platform\TenantLookupController;
use App\Http\Controllers\Api\V1\Platform\TenantMembershipController;
use App\Http\Controllers\Api\V1\Platform\TenantUserController;
use App\Http\Controllers\Api\V1\PlatformSessionController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\PlatformAdmin\CoreProxyController;
use App\Http\Controllers\PlatformAdmin\CoreSessionController;
use App\Http\Controllers\PlatformAdmin\NotificationProxyController;
use App\Http\Controllers\PlatformAdmin\TenantAppOnboardingController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('health', fn () => response()->json([
        'status' => 'ok',
        'service' => config('app.name', 'Stelfaro Platform API'),
        'version' => app()->version(),
        'timestamp' => now()->toISOString(),
    ]));

    Route::middleware(['web', 'auth', 'verified'])->group(function (): void {
        Route::get('me', PlatformSessionController::class);
        Route::patch('me/active-membership/{membership}', [TenantMembershipController::class, 'setActive']);
        Route::get('admin/platform/users', [GlobalUserController::class, 'index']);
        Route::get('admin/platform/tenants/by-core-empresa/{coreEmpresaId}', [TenantLookupController::class, 'byCoreEmpresa']);
        Route::get('admin/core/session', CoreSessionController::class);
        Route::get('admin/platform/apps', [TenantAppOnboardingController::class, 'apps']);
        Route::post('admin/platform/tenants', [TenantAppOnboardingController::class, 'store']);
        Route::get('platform/tenants/{tenant}/users', [TenantUserController::class, 'index']);
        Route::post('platform/tenants/{tenant}/invitations', [TenantUserController::class, 'invite']);
        Route::post('platform/invitations/{token}/accept', [TenantInvitationController::class, 'accept']);
        Route::post('platform/invitations/{invitation}/resend', [TenantInvitationController::class, 'resend']);
        Route::get('platform/invitations/{invitation}/delivery', [TenantInvitationController::class, 'delivery']);
        Route::patch('platform/memberships/{membership}/role', [TenantMembershipController::class, 'updateRole']);
        Route::patch('platform/memberships/{membership}/suspend', [TenantMembershipController::class, 'suspend']);
        Route::patch('platform/memberships/{membership}/reactivate', [TenantMembershipController::class, 'reactivate']);
        Route::delete('platform/memberships/{membership}', [TenantMembershipController::class, 'destroy']);
        Route::any('admin/core/{path?}', CoreProxyController::class)
            ->where('path', '.*');
        Route::any('admin/notifications/{path?}', NotificationProxyController::class)
            ->where('path', '.*');
        Route::post('logout', [AuthenticatedSessionController::class, 'destroy']);
    });
});

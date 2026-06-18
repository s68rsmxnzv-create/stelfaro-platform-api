<?php

use App\Http\Controllers\Api\V1\PlatformSessionController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\PlatformAdmin\CoreProxyController;
use App\Http\Controllers\PlatformAdmin\CoreSessionController;
use App\Http\Controllers\PlatformAdmin\NotificationProxyController;
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
        Route::get('admin/core/session', CoreSessionController::class);
        Route::any('admin/core/{path?}', CoreProxyController::class)
            ->where('path', '.*');
        Route::any('admin/notifications/{path?}', NotificationProxyController::class)
            ->where('path', '.*');
        Route::post('logout', [AuthenticatedSessionController::class, 'destroy']);
    });
});

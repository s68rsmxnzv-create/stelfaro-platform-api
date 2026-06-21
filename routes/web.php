<?php

use App\Http\Controllers\Api\V1\Platform\TenantInvitationController;
use App\Http\Controllers\Api\V1\Platform\TenantLookupController;
use App\Http\Controllers\Api\V1\Platform\TenantMembershipController;
use App\Http\Controllers\Api\V1\Platform\TenantUserController;
use App\Http\Controllers\Api\V1\PlatformSessionController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\CoreBillingSessionController;
use App\Http\Controllers\PlatformAdmin\CoreSessionController;
use App\Http\Controllers\PlatformAdmin\NotificationProxyController;
use App\Http\Controllers\PlatformAdmin\TenantAppOnboardingController;
use App\Http\Controllers\PlatformPortalController;
use App\Http\Controllers\PlatformRedirectController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::domain(config('platform.hosts.taller'))
    ->middleware(['auth', 'verified'])
    ->group(function (): void {
        Route::get('/', [PlatformPortalController::class, 'taller'])->name('apps.taller');
        Route::get('/recepcion', [PlatformPortalController::class, 'tallerReception'])->name('apps.taller.reception');
        Route::get('/diagnostico', [PlatformPortalController::class, 'tallerDiagnosis'])->name('apps.taller.diagnosis');
        Route::get('/ordenes', [PlatformPortalController::class, 'tallerOrders'])->name('apps.taller.orders');
        Route::get('/facturacion/{documentSlug?}', [PlatformPortalController::class, 'tallerBilling'])->name('apps.taller.billing');
        Route::get('/comprobantes', [PlatformPortalController::class, 'tallerArtifacts'])->name('apps.taller.artifacts');
        Route::get('/eventos-mh/{eventSlug?}', [PlatformPortalController::class, 'tallerMhEvents'])->name('apps.taller.mh-events');
        Route::get('/respuestas-mh', [PlatformPortalController::class, 'tallerMhResponses'])->name('apps.taller.mh-responses');
        Route::get('/respuestas-eventos-mh', [PlatformPortalController::class, 'tallerMhEventResponses'])->name('apps.taller.mh-event-responses');
        Route::get('/configuracion', [PlatformPortalController::class, 'tallerFiscalSettings'])->name('apps.taller.settings');
        Route::redirect('/configuracion-fiscal', '/configuracion')->name('apps.taller.fiscal-settings');
        Route::get('/platform/core-billing-session', CoreBillingSessionController::class)
            ->name('apps.taller.core-billing-session');
        Route::post('/logout', [AuthenticatedSessionController::class, 'destroy']);
    });

Route::domain(config('platform.hosts.facturacion'))
    ->middleware(['auth', 'verified'])
    ->group(function (): void {
        Route::get('/', [PlatformPortalController::class, 'facturacion'])->name('apps.facturacion');
        Route::get('/configuracion', [PlatformPortalController::class, 'facturacionSettings'])->name('apps.facturacion.settings');
        Route::redirect('/configuracion-fiscal', '/configuracion')->name('apps.facturacion.fiscal-settings');
        Route::get('/platform/core-billing-session', CoreBillingSessionController::class)
            ->name('apps.facturacion.core-billing-session');
        Route::post('/logout', [AuthenticatedSessionController::class, 'destroy']);
    });

Route::domain(config('platform.hosts.admin'))
    ->prefix('platform-api/v1')
    ->middleware(['auth', 'verified'])
    ->group(function (): void {
        Route::get('/me', PlatformSessionController::class);
        Route::get('/admin/core/session', CoreSessionController::class);
        Route::get('/admin/platform/apps', [TenantAppOnboardingController::class, 'apps']);
        Route::post('/admin/platform/tenants', [TenantAppOnboardingController::class, 'store']);
        Route::get('/admin/platform/tenants/by-core-empresa/{coreEmpresaId}', [TenantLookupController::class, 'byCoreEmpresa']);
        Route::get('/platform/tenants/{tenant}/users', [TenantUserController::class, 'index']);
        Route::post('/platform/tenants/{tenant}/invitations', [TenantUserController::class, 'invite']);
        Route::post('/platform/invitations/{invitation}/resend', [TenantInvitationController::class, 'resend']);
        Route::patch('/platform/memberships/{membership}/role', [TenantMembershipController::class, 'updateRole']);
        Route::patch('/platform/memberships/{membership}/suspend', [TenantMembershipController::class, 'suspend']);
        Route::patch('/platform/memberships/{membership}/reactivate', [TenantMembershipController::class, 'reactivate']);
        Route::delete('/platform/memberships/{membership}', [TenantMembershipController::class, 'destroy']);
        Route::any('/admin/notifications/{path?}', NotificationProxyController::class)
            ->where('path', '.*');
        Route::post('/logout', [AuthenticatedSessionController::class, 'destroy']);
    });

Route::domain(config('platform.hosts.platform'))
    ->group(function (): void {
        Route::middleware(['auth', 'verified'])->group(function (): void {
            Route::get('/', PlatformRedirectController::class)->name('portal.home');
            Route::get('/dashboard', PlatformRedirectController::class)->name('dashboard');
        });

        Route::middleware('auth')->group(function (): void {
            Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
            Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
            Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
        });

        require __DIR__.'/auth.php';
    });

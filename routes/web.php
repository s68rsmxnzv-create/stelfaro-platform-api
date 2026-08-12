<?php

use App\Http\Controllers\Api\V1\Platform\AdminTenantRequestController;
use App\Http\Controllers\Api\V1\Platform\GlobalUserController;
use App\Http\Controllers\Api\V1\Platform\InternalNotificationController;
use App\Http\Controllers\Api\V1\Platform\PlatformAuditLogController;
use App\Http\Controllers\Api\V1\Platform\SubscriptionController;
use App\Http\Controllers\Api\V1\Platform\TenantFiscalAssignmentController;
use App\Http\Controllers\Api\V1\Platform\TenantInvitationController;
use App\Http\Controllers\Api\V1\Platform\TenantLookupController;
use App\Http\Controllers\Api\V1\Platform\TenantMembershipController;
use App\Http\Controllers\Api\V1\Platform\TenantPurgeController;
use App\Http\Controllers\Api\V1\Platform\TenantUserController;
use App\Http\Controllers\Api\V1\PlatformSessionController;
use App\Http\Controllers\CoreBillingSessionController;
use App\Http\Controllers\PlatformAdmin\CoreSessionController;
use App\Http\Controllers\PlatformAdmin\NotificationProxyController;
use App\Http\Controllers\PlatformAdmin\TenantAppOnboardingController;
use App\Http\Controllers\PlatformInvitationPageController;
use App\Http\Controllers\PlatformPortalController;
use App\Http\Controllers\PlatformRedirectController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicQuotationController;
use App\Http\Controllers\PublicWorkshopDeviceController;
use App\Http\Controllers\PublicWorkshopPhotoController;
use App\Http\Controllers\ShortDteQrProxyController;
use App\Http\Controllers\WompiPaymentConfirmationController;
use App\Http\Controllers\WompiPaymentReturnController;
use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Support\Platform\PortalUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/payments/wompi/return', WompiPaymentReturnController::class)
    ->name('payments.wompi.return');
Route::get('/payments/wompi/confirmation', WompiPaymentConfirmationController::class)
    ->name('payments.wompi.confirmation');

Route::domain(config('platform.hosts.facturacion'))
    ->get('/q/{destination}/{document}/{token}', ShortDteQrProxyController::class)
    ->where([
        'destination' => 'm|d',
        'document' => '[0-9]+',
        'token' => '[A-Za-z0-9_-]{12}',
    ])
    ->name('dte.qr.short');

Route::domain(config('platform.hosts.taller'))
    ->group(function (): void {
        Route::get('/equipo/{token}', [PublicWorkshopDeviceController::class, 'show'])->name('workshop.device.public.legacy');
        Route::get('/fotos/{token}', [PublicWorkshopPhotoController::class, 'show'])->name('workshop.photos.public.legacy');
        Route::get('/fotos/{token}/imagenes/{photo}', [PublicWorkshopPhotoController::class, 'photo'])->name('workshop.photos.image.legacy');
    });

Route::domain(config('platform.portal.host'))
    ->prefix(trim(config('platform.paths.taller', '/taller'), '/'))
    ->group(function (): void {
        Route::get('/equipo/{token}', [PublicWorkshopDeviceController::class, 'show'])->name('workshop.device.public');
        Route::get('/fotos/{token}', [PublicWorkshopPhotoController::class, 'show'])->name('workshop.photos.public');
        Route::get('/fotos/{token}/imagenes/{photo}', [PublicWorkshopPhotoController::class, 'photo'])->name('workshop.photos.image');
    });

// Compatibilidad con enlaces generados antes de servir las aplicaciones bajo un único origen.
Route::domain(config('platform.portal.host'))
    ->get('/eventos-mh/{eventSlug?}', function (Request $request, ?string $eventSlug = null) {
        $target = PortalUrl::app('facturacion', '/eventos-mh'.($eventSlug ? '/'.$eventSlug : ''));

        if ($request->getQueryString()) {
            $target .= '?'.$request->getQueryString();
        }

        return redirect()->away($target);
    })
    ->name('apps.facturacion.mh-events.legacy');

Route::domain(config('platform.portal.host'))
    ->prefix(trim(config('platform.paths.taller', '/taller'), '/'))
    ->middleware(['auth', 'verified', EnsurePasswordIsChanged::class, 'tenant.app:taller'])
    ->group(function (): void {
        Route::get('/', [PlatformPortalController::class, 'taller'])->name('apps.taller');
        Route::get('/recepcion', [PlatformPortalController::class, 'tallerReception'])->name('apps.taller.reception');
        Route::redirect('/diagnostico', '/taller/ordenes')->name('apps.taller.diagnosis');
        Route::get('/ordenes', [PlatformPortalController::class, 'tallerOrders'])->name('apps.taller.orders');
        Route::get('/clientes', [PlatformPortalController::class, 'tallerCustomers'])->name('apps.taller.customers');
        Route::get('/catalogo', [PlatformPortalController::class, 'tallerCatalog'])->name('apps.taller.catalog');
        Route::get('/inventario', [PlatformPortalController::class, 'tallerInventory'])->name('apps.taller.inventory');
        Route::get('/caja', [PlatformPortalController::class, 'tallerCash'])->name('apps.taller.cash');
        Route::get('/ordenes-trabajo', [PlatformPortalController::class, 'tallerCommercialOrders'])->name('apps.taller.commercial-orders');
        Route::get('/pendientes', [PlatformPortalController::class, 'tallerFollowUps'])->name('apps.taller.follow-ups');
        Route::get('/anexos', [PlatformPortalController::class, 'tallerAnnexes'])->name('apps.taller.annexes');
        Route::get('/facturacion/{documentSlug?}', [PlatformPortalController::class, 'tallerBilling'])->name('apps.taller.billing');
        Route::get('/comprobantes/{artifactSlug?}', [PlatformPortalController::class, 'tallerArtifacts'])->name('apps.taller.artifacts');
        Route::get('/auditoria', [PlatformPortalController::class, 'tallerAudit'])->name('apps.taller.audit');
        Route::get('/eventos-mh/{eventSlug?}', [PlatformPortalController::class, 'tallerMhEvents'])->name('apps.taller.mh-events');
        Route::get('/respuestas-mh', [PlatformPortalController::class, 'tallerMhResponses'])->name('apps.taller.mh-responses');
        Route::get('/respuestas-eventos-mh', [PlatformPortalController::class, 'tallerMhEventResponses'])->name('apps.taller.mh-event-responses');
        Route::get('/configuracion', [PlatformPortalController::class, 'tallerFiscalSettings'])->name('apps.taller.settings');
        Route::redirect('/configuracion-fiscal', '/taller/configuracion')->name('apps.taller.fiscal-settings');
        Route::get('/platform/core-billing-session', CoreBillingSessionController::class)
            ->name('apps.taller.core-billing-session');
    });

Route::domain(config('platform.portal.host'))
    ->prefix(trim(config('platform.paths.facturacion', '/facturacion'), '/'))
    ->middleware([
        'auth',
        'verified',
        EnsurePasswordIsChanged::class,
        'tenant.app:facturacion',
        'tenant.preserve-workshop-context',
    ])
    ->group(function (): void {
        Route::get('/', [PlatformPortalController::class, 'facturacion'])->name('apps.facturacion');
        Route::get('/clientes', [PlatformPortalController::class, 'facturacionCustomers'])->name('apps.facturacion.customers');
        Route::get('/catalogo', [PlatformPortalController::class, 'facturacionCatalog'])->name('apps.facturacion.catalog');
        Route::get('/inventario', [PlatformPortalController::class, 'facturacionInventory'])->name('apps.facturacion.inventory');
        Route::get('/caja', [PlatformPortalController::class, 'facturacionCash'])->name('apps.facturacion.cash');
        Route::get('/ordenes-trabajo', [PlatformPortalController::class, 'facturacionCommercialOrders'])->name('apps.facturacion.commercial-orders');
        Route::get('/pendientes', [PlatformPortalController::class, 'facturacionFollowUps'])->name('apps.facturacion.follow-ups');
        Route::get('/anexos', [PlatformPortalController::class, 'facturacionAnnexes'])->name('apps.facturacion.annexes');
        Route::get('/{documentSlug}', [PlatformPortalController::class, 'facturacionBilling'])
            ->whereIn('documentSlug', ['fe', 'ccf', 'se', 'nc', 'nd'])
            ->name('apps.facturacion.billing');
        Route::get('/comprobantes/{artifactSlug?}', [PlatformPortalController::class, 'facturacionArtifacts'])->name('apps.facturacion.artifacts');
        Route::get('/auditoria', [PlatformPortalController::class, 'facturacionAudit'])->name('apps.facturacion.audit');
        Route::get('/eventos-mh/{eventSlug?}', [PlatformPortalController::class, 'facturacionMhEvents'])->name('apps.facturacion.mh-events');
        Route::get('/respuestas-mh', [PlatformPortalController::class, 'facturacionMhResponses'])->name('apps.facturacion.mh-responses');
        Route::get('/respuestas-eventos-mh', [PlatformPortalController::class, 'facturacionMhEventResponses'])->name('apps.facturacion.mh-event-responses');
        Route::get('/configuracion', [PlatformPortalController::class, 'facturacionSettings'])->name('apps.facturacion.settings');
        Route::redirect('/configuracion-fiscal', '/facturacion/configuracion')->name('apps.facturacion.fiscal-settings');
        Route::get('/platform/core-billing-session', CoreBillingSessionController::class)
            ->name('apps.facturacion.core-billing-session');
    });

foreach (['taller', 'facturacion'] as $legacyApp) {
    if (config("platform.hosts.{$legacyApp}") === config('platform.portal.host')) {
        continue;
    }

    Route::domain(config("platform.hosts.{$legacyApp}"))
        ->get('/{path?}', function (Request $request, ?string $path = null) use ($legacyApp) {
            if (in_array($path, ['login', 'forgot-password', 'change-temporary-password'], true)) {
                return redirect()->away(PortalUrl::path('/'.$path));
            }

            if ($legacyApp === 'facturacion' && is_string($path) && str_starts_with($path, 'facturacion/')) {
                $path = substr($path, strlen('facturacion/'));
            }

            $target = PortalUrl::app($legacyApp, $path ? '/'.$path : '/');
            if ($request->getQueryString()) {
                $target .= '?'.$request->getQueryString();
            }

            return redirect()->away($target);
        })
        ->where('path', '.*');
}

$adminApiRoutes = function (): void {
    Route::get('/me', PlatformSessionController::class);
    Route::get('/admin/platform/users', [GlobalUserController::class, 'index']);
    Route::get('/admin/platform/audit-logs', [PlatformAuditLogController::class, 'index']);
    Route::get('/admin/platform/requests', [AdminTenantRequestController::class, 'index']);
    Route::patch('/admin/platform/requests/{tenantRequest}', [AdminTenantRequestController::class, 'update']);
    Route::post('/admin/platform/requests/{tenantRequest}/review', [AdminTenantRequestController::class, 'review']);
    Route::post('/admin/platform/requests/{tenantRequest}/create-user', [AdminTenantRequestController::class, 'createUser']);
    Route::post('/admin/platform/requests/{tenantRequest}/create-branch', [AdminTenantRequestController::class, 'createBranch']);
    Route::post('/admin/platform/requests/{tenantRequest}/create-point-of-sale', [AdminTenantRequestController::class, 'createPointOfSale']);
    Route::get('/admin/core/session', CoreSessionController::class);
    Route::get('/admin/platform/subscriptions', [SubscriptionController::class, 'index']);
    Route::put('/admin/platform/tenants/{tenant}/subscription', [SubscriptionController::class, 'update']);
    Route::get('/admin/platform/tenants/by-core-empresa/{coreEmpresaId}/subscription', [SubscriptionController::class, 'showByCoreEmpresa']);
    Route::put('/admin/platform/tenants/by-core-empresa/{coreEmpresaId}/subscription', [SubscriptionController::class, 'updateByCoreEmpresa']);
    Route::get('/admin/platform/apps', [TenantAppOnboardingController::class, 'apps']);
    Route::post('/admin/platform/tenants', [TenantAppOnboardingController::class, 'store']);
    Route::get('/admin/platform/tenants/by-core-empresa/{coreEmpresaId}', [TenantLookupController::class, 'byCoreEmpresa']);
    Route::delete('/admin/platform/tenants/by-core-empresa/{coreEmpresaId}', [TenantPurgeController::class, 'destroyByCoreEmpresa']);
    Route::post('/admin/platform/tenants/by-core-empresa/{coreEmpresaId}/purge', [TenantPurgeController::class, 'destroyByCoreEmpresa']);
    Route::get('/platform/notifications', [InternalNotificationController::class, 'index']);
    Route::post('/platform/notifications/read-all', [InternalNotificationController::class, 'readAll']);
    Route::post('/platform/notifications/{notification}/read', [InternalNotificationController::class, 'read']);
    Route::delete('/platform/notifications/{notification}', [InternalNotificationController::class, 'destroy']);
    Route::get('/platform/tenants/{tenant}/users', [TenantUserController::class, 'index']);
    Route::get('/platform/tenants/{tenant}/audit-logs', [PlatformAuditLogController::class, 'tenant']);
    Route::get('/platform/tenants/{tenant}/subscription', [SubscriptionController::class, 'showForTenant']);
    Route::get('/platform/tenants/by-core-empresa/{coreEmpresaId}/subscription', [SubscriptionController::class, 'showForTenantByCoreEmpresa']);
    Route::get('/platform/tenants/{tenant}/fiscal-scope', [TenantFiscalAssignmentController::class, 'scope']);
    Route::post('/platform/tenants/{tenant}/users', [TenantUserController::class, 'store']);
    Route::post('/platform/tenants/{tenant}/invitations', [TenantUserController::class, 'invite']);
    Route::post('/platform/invitations/{invitation}/resend', [TenantInvitationController::class, 'resend']);
    Route::get('/platform/invitations/{invitation}/delivery', [TenantInvitationController::class, 'delivery']);
    Route::patch('/platform/memberships/{membership}/role', [TenantMembershipController::class, 'updateRole']);
    Route::post('/platform/memberships/{membership}/temporary-password', [TenantMembershipController::class, 'resetTemporaryPassword']);
    Route::put('/platform/memberships/{membership}/fiscal-assignments', [TenantFiscalAssignmentController::class, 'store']);
    Route::patch('/platform/memberships/{membership}/suspend', [TenantMembershipController::class, 'suspend']);
    Route::patch('/platform/memberships/{membership}/reactivate', [TenantMembershipController::class, 'reactivate']);
    Route::delete('/platform/memberships/{membership}', [TenantMembershipController::class, 'destroy']);
    Route::any('/admin/notifications/{path?}', NotificationProxyController::class)
        ->where('path', '.*');
};

Route::domain(config('platform.hosts.admin'))
    ->prefix('platform-api/v1')
    ->middleware(['auth', 'verified', EnsurePasswordIsChanged::class])
    ->group($adminApiRoutes);

Route::domain(config('platform.portal.host'))
    ->prefix('platform-api/v1')
    ->middleware(['auth', 'verified', EnsurePasswordIsChanged::class])
    ->group($adminApiRoutes);

Route::domain(config('platform.portal.host'))
    ->prefix(trim(config('platform.paths.admin', '/administracion'), '/'))
    ->middleware(['auth', 'verified', EnsurePasswordIsChanged::class])
    ->get('/{path?}', function () {
        $index = public_path('administracion/index.html');
        abort_unless(is_file($index), 503, 'El panel administrativo no ha sido compilado.');

        return response()->file($index, ['Cache-Control' => 'no-cache, no-store, must-revalidate']);
    })
    ->where('path', '.*')
    ->name('platform.admin.spa');

Route::domain(config('platform.portal.host'))
    ->group(function (): void {
        Route::get('/', [PlatformPortalController::class, 'home'])
            ->middleware(EnsurePasswordIsChanged::class)
            ->name('portal.home');

        Route::inertia('/terminos-y-condiciones', 'Legal/Terms')->name('portal.terms');
        Route::inertia('/politica-de-privacidad', 'Legal/Privacy')->name('portal.privacy');

        Route::get('/invitations/{token}', PlatformInvitationPageController::class)
            ->name('platform.invitations.accept');

        Route::middleware(['auth', 'verified', EnsurePasswordIsChanged::class])->group(function (): void {
            Route::get('/dashboard', PlatformRedirectController::class)->name('dashboard');
        });

        Route::middleware(['auth', EnsurePasswordIsChanged::class])->group(function (): void {
            Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
            Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
            Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
        });

    });

Route::get('/cotizaciones/{token}', PublicQuotationController::class)->name('quotations.public');

if (config('platform.hosts.platform') !== config('platform.portal.host')) {
    Route::domain(config('platform.hosts.platform'))
        ->get('/{path?}', function (Request $request, ?string $path = null) {
            $target = PortalUrl::path($path ? '/'.$path : '/');
            if ($request->getQueryString()) {
                $target .= '?'.$request->getQueryString();
            }

            return redirect()->away($target);
        })
        ->where('path', '.*');
}

// La autenticación permanece disponible también en los hosts heredados durante
// la transición; sus vistas GET se redirigen al origen canónico.
require __DIR__.'/auth.php';

<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\CoreBillingSessionController;
use App\Http\Controllers\PlatformPortalController;
use App\Http\Controllers\PlatformRedirectController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::domain(config('platform.hosts.taller'))
    ->middleware(['auth', 'verified'])
    ->group(function (): void {
        Route::get('/', [PlatformPortalController::class, 'taller'])->name('apps.taller');
        Route::get('/facturacion/{documentSlug?}', [PlatformPortalController::class, 'tallerBilling'])->name('apps.taller.billing');
        Route::get('/comprobantes', [PlatformPortalController::class, 'tallerArtifacts'])->name('apps.taller.artifacts');
        Route::get('/eventos-mh/{eventSlug?}', [PlatformPortalController::class, 'tallerMhEvents'])->name('apps.taller.mh-events');
        Route::get('/respuestas-mh', [PlatformPortalController::class, 'tallerMhResponses'])->name('apps.taller.mh-responses');
        Route::get('/respuestas-eventos-mh', [PlatformPortalController::class, 'tallerMhEventResponses'])->name('apps.taller.mh-event-responses');
        Route::get('/configuracion-fiscal', [PlatformPortalController::class, 'tallerFiscalSettings'])->name('apps.taller.fiscal-settings');
        Route::get('/platform/core-billing-session', CoreBillingSessionController::class)
            ->name('apps.taller.core-billing-session');
        Route::post('/logout', [AuthenticatedSessionController::class, 'destroy']);
    });

Route::domain(config('platform.hosts.facturacion'))
    ->middleware(['auth', 'verified'])
    ->group(function (): void {
        Route::get('/', [PlatformPortalController::class, 'facturacion'])->name('apps.facturacion');
        Route::get('/platform/core-billing-session', CoreBillingSessionController::class)
            ->name('apps.facturacion.core-billing-session');
        Route::post('/logout', [AuthenticatedSessionController::class, 'destroy']);
    });

Route::domain(config('platform.hosts.platform'))
    ->group(function (): void {
        Route::middleware(['auth', 'verified'])->group(function (): void {
            Route::get('/', [PlatformPortalController::class, 'home'])->name('portal.home');
            Route::get('/dashboard', PlatformRedirectController::class)->name('dashboard');
        });

        Route::middleware('auth')->group(function (): void {
            Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
            Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
            Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
        });

        require __DIR__.'/auth.php';
    });

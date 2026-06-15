<?php

use App\Http\Controllers\PlatformPortalController;
use App\Http\Controllers\PlatformRedirectController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/', [PlatformPortalController::class, 'home'])->name('portal.home');
    Route::get('/dashboard', PlatformRedirectController::class)->name('dashboard');
    Route::get('/taller', [PlatformPortalController::class, 'taller'])->name('apps.taller');
    Route::get('/facturacion', [PlatformPortalController::class, 'facturacion'])->name('apps.facturacion');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';

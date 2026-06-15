<?php

use App\Http\Controllers\PlatformPortalController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PlatformPortalController::class, 'home'])->name('portal.home');
Route::get('/taller', [PlatformPortalController::class, 'taller'])->name('apps.taller');
Route::get('/facturacion', [PlatformPortalController::class, 'facturacion'])->name('apps.facturacion');

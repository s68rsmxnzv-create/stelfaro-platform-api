<?php

namespace App\Http\Controllers;

use App\Services\PlatformSessionResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PlatformRedirectController extends Controller
{
    public function __construct(
        private readonly PlatformSessionResolver $sessionResolver,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $session = $this->sessionResolver->resolve($request->user());
        $defaultApp = $session['default_app'] ?? null;

        return match ($defaultApp['id'] ?? null) {
            'taller' => redirect()->route('apps.taller'),
            'facturacion' => redirect()->route('apps.facturacion'),
            default => redirect()->route('portal.home'),
        };
    }
}

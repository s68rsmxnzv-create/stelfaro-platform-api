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

        return redirect($defaultApp['local_path'] ?? 'https://'.config('platform.hosts.platform'));
    }
}

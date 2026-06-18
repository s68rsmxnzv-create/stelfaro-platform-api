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

        abort_if(! $defaultApp, 403, 'No tienes apps activas asignadas.');

        return redirect($defaultApp['local_path']);
    }
}

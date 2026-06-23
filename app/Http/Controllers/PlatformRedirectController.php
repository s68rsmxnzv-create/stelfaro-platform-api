<?php

namespace App\Http\Controllers;

use App\Services\PlatformAdminAccess;
use App\Services\PlatformSessionResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PlatformRedirectController extends Controller
{
    public function __construct(
        private readonly PlatformSessionResolver $sessionResolver,
        private readonly PlatformAdminAccess $platformAdminAccess,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        if ($this->platformAdminAccess->allows($request->user())) {
            return redirect('https://'.config('platform.hosts.admin'));
        }

        $session = $this->sessionResolver->resolve($request->user());
        $defaultApp = $session['default_app'] ?? null;

        if (! $defaultApp) {
            abort_unless($this->platformAdminAccess->allows($request->user()), 403, 'No tienes apps activas asignadas.');

            return redirect('https://'.config('platform.hosts.admin'));
        }

        return redirect($defaultApp['local_path']);
    }
}

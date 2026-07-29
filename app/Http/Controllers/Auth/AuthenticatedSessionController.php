<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\CoreBillingSessionBroker;
use App\Services\PlatformAdminAccess;
use App\Services\PlatformAuditLogger;
use App\Services\PlatformSessionResolver;
use App\Support\Platform\PortalUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class AuthenticatedSessionController extends Controller
{
    public function __construct(
        private readonly PlatformSessionResolver $sessionResolver,
        private readonly PlatformAdminAccess $platformAdminAccess,
        private readonly CoreBillingSessionBroker $coreBillingSessions,
        private readonly PlatformAuditLogger $audit,
    ) {}

    /**
     * Display the login view.
     */
    public function create(Request $request): Response
    {
        $redirect = $request->query('redirect');

        if (is_string($redirect) && str_starts_with($redirect, '/')) {
            $request->session()->put('url.intended', $request->getSchemeAndHttpHost().$redirect);
        }

        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): SymfonyResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $user = $request->user()->fresh();
        $this->audit->record($request, 'auth.login', [
            'user_email_sha256' => hash('sha256', strtolower((string) $user->email)),
        ]);

        if ($user->must_change_password) {
            $target = $request->getSchemeAndHttpHost().'/change-temporary-password';

            if ($request->header('X-Inertia')) {
                return Inertia::location($target);
            }

            return redirect($target);
        }

        $session = $this->sessionResolver->resolve($user);
        $target = $session['default_app']['local_path'] ?? (
            $this->platformAdminAccess->allows($user)
                ? PortalUrl::app('admin')
                : PortalUrl::base()
        );

        if ($request->header('X-Inertia')) {
            return Inertia::location($target);
        }

        return redirect()->intended($target);
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): SymfonyResponse
    {
        $platformSessionId = $request->session()->getId();
        $this->audit->record($request, 'auth.logout');

        Auth::guard('web')->logout();

        $this->coreBillingSessions->revokePlatformSession($platformSessionId);

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        $target = $request->getSchemeAndHttpHost().'/';

        if ($request->header('X-Inertia')) {
            return Inertia::location($target);
        }

        return redirect($target);
    }
}

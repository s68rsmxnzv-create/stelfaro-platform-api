<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\PlatformSessionResolver;
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
    ) {}

    /**
     * Display the login view.
     */
    public function create(Request $request): Response
    {
        $redirect = $request->query('redirect');

        if (is_string($redirect) && str_starts_with($redirect, '/')) {
            $request->session()->put('url.intended', 'https://'.config('platform.hosts.platform').$redirect);
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

        if ($user->must_change_password) {
            $target = 'https://'.config('platform.hosts.platform').'/change-temporary-password';

            if ($request->header('X-Inertia')) {
                return Inertia::location($target);
            }

            return redirect($target);
        }

        $session = $this->sessionResolver->resolve($user);
        $target = $session['default_app']['local_path'] ?? 'https://'.config('platform.hosts.platform');

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
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        $target = 'https://'.config('platform.hosts.platform').'/login';

        if ($request->header('X-Inertia')) {
            return Inertia::location($target);
        }

        return redirect($target);
    }
}

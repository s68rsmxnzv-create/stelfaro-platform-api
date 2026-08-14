<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Cash\CashOnboardingNotification;
use App\Services\Legal\LegalAcceptanceRequirement;
use App\Services\PlatformSessionResolver;
use App\Support\Platform\PortalUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class TemporaryPasswordController extends Controller
{
    public function __construct(
        private readonly PlatformSessionResolver $sessionResolver,
        private readonly CashOnboardingNotification $cashOnboarding,
        private readonly LegalAcceptanceRequirement $legalAcceptance,
    ) {}

    public function edit(): Response
    {
        return Inertia::render('Auth/ChangeTemporaryPassword');
    }

    public function update(Request $request): SymfonyResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $request->user()->forceFill([
            'password' => Hash::make($validated['password']),
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->save();

        if ($this->legalAcceptance->pending($request->user()->fresh())) {
            $target = $request->getSchemeAndHttpHost().'/aceptacion-legal';

            if ($request->header('X-Inertia')) {
                return Inertia::location($target);
            }

            return redirect($target);
        }

        $session = $this->sessionResolver->resolve($request->user());
        $this->cashOnboarding->ensure($request->user(), $session);
        $target = $session['default_app']['local_path'] ?? PortalUrl::base();

        if ($request->header('X-Inertia')) {
            return Inertia::location($target);
        }

        return redirect()->intended($target);
    }
}

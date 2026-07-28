<?php

namespace App\Http\Middleware;

use App\Services\CoreBillingSessionBroker;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ExpireIdlePlatformSession
{
    public function __construct(
        private readonly CoreBillingSessionBroker $coreBillingSessions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('web')->check() || ! $request->hasSession()) {
            return $next($request);
        }

        $now = now()->timestamp;
        $timeout = max(1, (int) config('session.lifetime', 45)) * 60;
        $lastActivity = (int) $request->session()->get('platform_last_activity_at', 0);

        if ($lastActivity > 0 && ($now - $lastActivity) > $timeout) {
            $platformSessionId = $request->session()->getId();

            Auth::guard('web')->logout();
            $this->coreBillingSessions->revokePlatformSession($platformSessionId);

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Sesión expirada por inactividad.'], 419);
            }

            return redirect($request->getSchemeAndHttpHost().'/login');
        }

        $request->session()->put('platform_last_activity_at', $now);

        return $next($request);
    }
}

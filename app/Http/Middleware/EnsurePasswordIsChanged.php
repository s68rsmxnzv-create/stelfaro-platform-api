<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordIsChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user()?->fresh();

        if (! $user?->must_change_password) {
            return $next($request);
        }

        $target = 'https://'.config('platform.hosts.platform').'/change-temporary-password';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Debes cambiar tu contrasena temporal antes de continuar.',
                'redirect' => $target,
            ], 423);
        }

        if ($request->header('X-Inertia')) {
            return Inertia::location($target);
        }

        return redirect($target);
    }
}

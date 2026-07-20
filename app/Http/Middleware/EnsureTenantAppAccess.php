<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\PlatformAccessPolicy;
use App\Services\PlatformSessionResolver;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantAppAccess
{
    public function __construct(
        private readonly PlatformAccessPolicy $accessPolicy,
        private readonly PlatformSessionResolver $sessionResolver,
    ) {}

    public function handle(Request $request, Closure $next, string $appKey): Response
    {
        $tenant = $this->tenantFor($request);

        if ($tenant && $this->accessPolicy->canAccessTenantApp($request->user(), $tenant, $appKey)) {
            return $next($request);
        }

        if (! $request->expectsJson() && $request->user()) {
            $defaultApp = $this->sessionResolver->resolve($request->user())['default_app'] ?? null;
            $fallback = $defaultApp['local_path'] ?? null;

            if (is_string($fallback) && $fallback !== '' && ($defaultApp['id'] ?? null) !== $appKey) {
                return $request->header('X-Inertia')
                    ? Inertia::location($fallback)
                    : redirect()->away($fallback);
            }
        }

        abort(403, 'Esta aplicación no está habilitada para la empresa activa.');
    }

    private function tenantFor(Request $request): ?Tenant
    {
        $routeTenant = $request->route('tenant');

        if ($routeTenant instanceof Tenant) {
            return $routeTenant;
        }

        if (is_numeric($routeTenant)) {
            return Tenant::query()->find((int) $routeTenant);
        }

        return $request->user()?->memberships()
            ->with('tenant')
            ->where('status', 'active')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first()
            ?->tenant;
    }
}

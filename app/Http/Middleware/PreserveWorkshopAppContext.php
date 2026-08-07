<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\PlatformAccessPolicy;
use App\Support\Platform\PortalUrl;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class PreserveWorkshopAppContext
{
    private const BILLING_DOCUMENT_SLUGS = ['fe', 'ccf', 'se', 'nc', 'nd'];

    public function __construct(private readonly PlatformAccessPolicy $accessPolicy) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->expectsJson() || $request->is('facturacion/platform/core-billing-session')) {
            return $next($request);
        }

        $tenant = $this->tenantFor($request);

        if (! $tenant || ! $this->accessPolicy->canAccessTenantApp($request->user(), $tenant, 'taller')) {
            return $next($request);
        }

        $relativePath = trim((string) $request->route('documentSlug'), '/');

        if ($relativePath === '') {
            $facturacionPrefix = trim(config('platform.paths.facturacion', '/facturacion'), '/');
            $requestPath = trim($request->path(), '/');
            $relativePath = trim((string) preg_replace('#^'.preg_quote($facturacionPrefix, '#').'/?#', '', $requestPath), '/');
        }

        $targetPath = $relativePath === ''
            ? '/'
            : (in_array(strtok($relativePath, '/'), self::BILLING_DOCUMENT_SLUGS, true)
                ? '/facturacion/'.$relativePath
                : '/'.$relativePath);

        $target = PortalUrl::app('taller', $targetPath);

        if ($request->getQueryString()) {
            $target .= '?'.$request->getQueryString();
        }

        return $request->header('X-Inertia')
            ? Inertia::location($target)
            : redirect()->away($target);
    }

    private function tenantFor(Request $request): ?Tenant
    {
        return $request->user()?->memberships()
            ->with('tenant')
            ->where('status', 'active')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first()
            ?->tenant;
    }
}

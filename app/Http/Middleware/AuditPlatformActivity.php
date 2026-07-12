<?php

namespace App\Http\Middleware;

use App\Services\PlatformAuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditPlatformActivity
{
    public function __construct(private readonly PlatformAuditLogger $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->shouldRecord($request, $response)) {
            $this->audit->record($request, $this->action($request), [
                'result' => $response->getStatusCode() >= 400 ? 'failed' : 'success',
                'status_code' => $response->getStatusCode(),
            ]);
        }

        return $response;
    }

    private function shouldRecord(Request $request, Response $response): bool
    {
        if (! config('security.audit.enabled', true)) {
            return false;
        }

        if (! $request->user()) {
            return false;
        }

        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return false;
        }

        if ($request->is('api/v1/webhooks/*')) {
            return false;
        }

        if ($request->is('login') || $request->is('logout') || $request->is('api/v1/logout') || $request->is('platform-api/v1/logout')) {
            return false;
        }

        return $response->getStatusCode() < 500;
    }

    private function action(Request $request): string
    {
        $routeName = $request->route()?->getName();
        if (is_string($routeName) && $routeName !== '') {
            return $routeName;
        }

        return strtolower($request->method()).'.'.str_replace('/', '.', trim($request->path(), '/'));
    }
}

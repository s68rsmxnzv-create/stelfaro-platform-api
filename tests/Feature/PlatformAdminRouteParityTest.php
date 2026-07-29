<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PlatformAdminRouteParityTest extends TestCase
{
    public function test_unified_origin_exposes_the_routes_consumed_by_the_admin_panel(): void
    {
        $routes = [
            ['GET', '/me'],
            ['GET', '/admin/platform/users'],
            ['GET', '/admin/platform/audit-logs'],
            ['GET', '/admin/platform/requests'],
            ['PATCH', '/admin/platform/requests/1'],
            ['POST', '/admin/platform/requests/1/review'],
            ['POST', '/admin/platform/requests/1/create-user'],
            ['POST', '/admin/platform/requests/1/create-branch'],
            ['POST', '/admin/platform/requests/1/create-point-of-sale'],
            ['GET', '/admin/platform/subscriptions'],
            ['PUT', '/admin/platform/tenants/1/subscription'],
            ['GET', '/admin/platform/tenants/by-core-empresa/1/subscription'],
            ['PUT', '/admin/platform/tenants/by-core-empresa/1/subscription'],
            ['GET', '/admin/platform/tenants/by-core-empresa/1'],
            ['DELETE', '/admin/platform/tenants/by-core-empresa/1'],
            ['POST', '/admin/platform/tenants/by-core-empresa/1/purge'],
            ['GET', '/admin/platform/apps'],
            ['POST', '/admin/platform/tenants'],
            ['GET', '/platform/notifications'],
            ['POST', '/platform/notifications/read-all'],
            ['POST', '/platform/notifications/1/read'],
            ['DELETE', '/platform/notifications/1'],
            ['GET', '/platform/tenants/1/users'],
            ['GET', '/platform/tenants/1/audit-logs'],
            ['GET', '/platform/tenants/1/fiscal-scope'],
            ['POST', '/platform/tenants/1/users'],
            ['PATCH', '/platform/memberships/1/role'],
            ['POST', '/platform/memberships/1/temporary-password'],
            ['PUT', '/platform/memberships/1/fiscal-assignments'],
            ['PATCH', '/platform/memberships/1/suspend'],
            ['PATCH', '/platform/memberships/1/reactivate'],
            ['DELETE', '/platform/memberships/1'],
        ];

        foreach ($routes as [$method, $path]) {
            $route = Route::getRoutes()->match(Request::create(
                'https://platform.stelfaro.com/platform-api/v1'.$path,
                $method,
            ));

            $this->assertStringStartsWith(
                'platform-api/v1/',
                $route->uri(),
                "{$method} {$path} no está expuesta para el panel administrativo.",
            );
        }
    }
}

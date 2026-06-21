<?php

namespace App\Services;

use App\Models\PlatformApp;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserTenantMembership;
use Illuminate\Support\Collection;

class PlatformSessionResolver
{
    /**
     * @return array<string, mixed>
     */
    public function resolve(User $user): array
    {
        $membership = $this->defaultMembership($user);

        if (! $membership) {
            return [
                'user' => $this->userPayload($user),
                'tenant' => null,
                'apps' => [],
                'default_app' => null,
                'redirect_url' => null,
            ];
        }

        $tenant = $membership->tenant;
        $apps = $this->activeApps($tenant);
        $defaultApp = $this->defaultApp($tenant, $apps);

        return [
            'user' => $this->userPayload($user),
            'tenant' => [
                'id' => $tenant->id,
                'slug' => $tenant->slug,
                'name' => $tenant->name,
                'role' => $membership->role,
            ],
            'apps' => $apps->map(fn (PlatformApp $app): array => $this->appPayload($app))->values()->all(),
            'default_app' => $defaultApp ? $this->appPayload($defaultApp) : null,
            'redirect_url' => $defaultApp ? $this->urlFor($defaultApp) : null,
        ];
    }

    private function defaultMembership(User $user): ?UserTenantMembership
    {
        return $user->memberships()
            ->with('tenant')
            ->where('status', 'active')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }

    /**
     * @return Collection<int, PlatformApp>
     */
    private function activeApps(Tenant $tenant): Collection
    {
        return $tenant->appAccesses()
            ->with('app')
            ->where('status', 'active')
            ->get()
            ->pluck('app')
            ->filter(fn (?PlatformApp $app): bool => $app !== null && $app->status === 'active')
            ->values();
    }

    /**
     * @param  Collection<int, PlatformApp>  $apps
     */
    private function defaultApp(Tenant $tenant, Collection $apps): ?PlatformApp
    {
        $defaultAccess = $tenant->appAccesses()
            ->with('app')
            ->where('status', 'active')
            ->where('is_default', true)
            ->first();

        if ($defaultAccess?->app && $defaultAccess->app->status === 'active') {
            return $defaultAccess->app;
        }

        if ($tenant->primary_app_id) {
            $primary = $apps->first(fn (PlatformApp $app): bool => $app->id === $tenant->primary_app_id);

            if ($primary) {
                return $primary;
            }
        }

        return $apps->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'platform_role' => $user->platform_role,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function appPayload(PlatformApp $app): array
    {
        return [
            'id' => $app->key,
            'name' => $app->name,
            'host' => $app->host,
            'default_path' => $app->default_path,
            'local_path' => $this->localPathFor($app),
            'url' => $this->urlFor($app),
        ];
    }

    private function localPathFor(PlatformApp $app): string
    {
        return match ($app->key) {
            'taller' => 'https://'.config('platform.hosts.taller'),
            'facturacion' => 'https://'.config('platform.hosts.facturacion'),
            default => 'https://'.config('platform.hosts.platform'),
        };
    }

    private function urlFor(PlatformApp $app): ?string
    {
        if (! $app->host) {
            return null;
        }

        return 'https://'.trim($app->host, '/').'/'.ltrim($app->default_path ?: '/', '/');
    }
}

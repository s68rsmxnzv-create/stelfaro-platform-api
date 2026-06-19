<?php

namespace App\Http\Controllers\PlatformAdmin;

use App\Http\Controllers\Controller;
use App\Models\PlatformApp;
use App\Models\Tenant;
use App\Services\PlatformAdminAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TenantAppOnboardingController extends Controller
{
    public function __construct(
        private readonly PlatformAdminAccess $adminAccess,
    ) {}

    public function apps(Request $request): JsonResponse
    {
        $this->adminAccess->authorize($request->user());

        return response()->json([
            'apps' => PlatformApp::query()
                ->where('status', 'active')
                ->orderByRaw("case when key = 'facturacion' then 0 else 1 end")
                ->orderBy('name')
                ->get()
                ->map(fn (PlatformApp $app): array => $this->appPayload($app))
                ->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->adminAccess->authorize($request->user());

        $validated = $request->validate([
            'core_empresa_id' => ['required', 'integer', 'min:1'],
            'core_tenant_id' => ['nullable', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'app_keys' => ['nullable', 'array'],
            'app_keys.*' => ['string', 'max:80'],
        ]);

        $selectedKeys = collect(['facturacion'])
            ->merge($validated['app_keys'] ?? [])
            ->map(fn (mixed $key): string => Str::slug((string) $key))
            ->filter()
            ->unique()
            ->values();

        $apps = PlatformApp::query()
            ->where('status', 'active')
            ->whereIn('key', $selectedKeys)
            ->get()
            ->keyBy('key');

        abort_unless($apps->has('facturacion'), 422, 'La app Facturacion debe existir y estar activa.');

        $tenant = DB::transaction(function () use ($request, $validated, $selectedKeys, $apps): Tenant {
            $facturacion = $apps->get('facturacion');
            $tenant = Tenant::query()->create([
                'slug' => $this->uniqueTenantSlug((string) ($validated['slug'] ?? $validated['name'])),
                'name' => (string) $validated['name'],
                'status' => 'active',
                'primary_app_id' => $facturacion?->id,
                'metadata' => [
                    'source' => 'platform_admin_onboarding',
                    'core_empresa_id' => (int) $validated['core_empresa_id'],
                    'core_tenant_id' => $validated['core_tenant_id'] ?? null,
                ],
            ]);

            $selectedKeys->each(function (string $key) use ($apps, $tenant): void {
                $app = $apps->get($key);
                if (! $app) {
                    return;
                }

                $tenant->appAccesses()->create([
                    'platform_app_id' => $app->id,
                    'status' => 'active',
                    'is_default' => $app->key === 'facturacion',
                    'metadata' => ['source' => 'platform_admin_onboarding'],
                ]);
            });

            $request->user()?->memberships()->create([
                'tenant_id' => $tenant->id,
                'role' => 'owner',
                'status' => 'active',
                'is_default' => ! $request->user()?->memberships()->where('is_default', true)->exists(),
                'metadata' => ['source' => 'platform_admin_onboarding'],
            ]);

            return $tenant->load('appAccesses.app', 'memberships');
        });

        return response()->json([
            'tenant' => [
                'id' => $tenant->id,
                'slug' => $tenant->slug,
                'name' => $tenant->name,
                'apps' => $tenant->appAccesses
                    ->map(fn ($access): array => [
                        ...$this->appPayload($access->app),
                        'is_default' => (bool) $access->is_default,
                    ])
                    ->values(),
            ],
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function appPayload(?PlatformApp $app): array
    {
        return [
            'key' => $app?->key,
            'name' => $app?->name,
            'host' => $app?->host,
            'default_path' => $app?->default_path,
            'is_core' => $app?->key === 'facturacion',
        ];
    }

    private function uniqueTenantSlug(string $base): string
    {
        $slug = Str::slug($base) ?: 'tenant';
        $candidate = $slug;
        $suffix = 2;

        while (Tenant::query()->where('slug', $candidate)->exists()) {
            $candidate = "{$slug}-{$suffix}";
            $suffix++;
        }

        return $candidate;
    }
}

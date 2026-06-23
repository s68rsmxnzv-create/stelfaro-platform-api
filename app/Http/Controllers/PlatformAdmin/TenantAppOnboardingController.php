<?php

namespace App\Http\Controllers\PlatformAdmin;

use App\Http\Controllers\Controller;
use App\Models\PlatformApp;
use App\Models\Tenant;
use App\Services\CoreFiscalCompanyValidator;
use App\Services\Platform\DirectTenantUserService;
use App\Services\Platform\TemporaryPasswordNotificationClient;
use App\Services\PlatformAdminAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class TenantAppOnboardingController extends Controller
{
    public function __construct(
        private readonly PlatformAdminAccess $adminAccess,
        private readonly CoreFiscalCompanyValidator $fiscalCompanyValidator,
        private readonly DirectTenantUserService $tenantUsers,
        private readonly TemporaryPasswordNotificationClient $temporaryPasswords,
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
            'make_default' => ['sometimes', 'boolean'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'owner_email' => ['nullable', 'email:rfc', 'max:255'],
            'environment' => ['nullable', 'string', 'in:00,01'],
        ]);
        try {
            $this->fiscalCompanyValidator->validateActiveEmpresa((int) $validated['core_empresa_id']);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'core_empresa_id' => [$exception->getMessage()],
            ]);
        }

        $requestedKeys = collect($validated['app_keys'] ?? [])
            ->map(fn (mixed $key): string => Str::slug((string) $key))
            ->filter()
            ->unique()
            ->values();

        $selectedKeys = collect(['facturacion'])
            ->merge($requestedKeys)
            ->unique()
            ->values();

        $defaultAppKey = $requestedKeys->first(fn (string $key): bool => $key !== 'facturacion') ?? 'facturacion';

        $apps = PlatformApp::query()
            ->where('status', 'active')
            ->whereIn('key', $selectedKeys)
            ->get()
            ->keyBy('key');

        abort_unless($apps->has('facturacion'), 422, 'La app Facturacion debe existir y estar activa.');

        $ownerCredentials = null;
        $tenant = DB::transaction(function () use ($request, $validated, $selectedKeys, $defaultAppKey, $apps, &$ownerCredentials): Tenant {
            $defaultApp = $apps->get($defaultAppKey) ?? $apps->get('facturacion');
            $tenant = Tenant::query()->create([
                'slug' => $this->uniqueTenantSlug((string) ($validated['slug'] ?? $validated['name'])),
                'name' => (string) $validated['name'],
                'status' => 'active',
                'primary_app_id' => $defaultApp?->id,
                'metadata' => [
                    'source' => 'platform_admin_onboarding',
                    'core_empresa_id' => (int) $validated['core_empresa_id'],
                    'core_tenant_id' => $validated['core_tenant_id'] ?? null,
                    'environment' => $validated['environment'] ?? null,
                ],
            ]);

            $selectedKeys->each(function (string $key) use ($apps, $tenant, $defaultAppKey): void {
                $app = $apps->get($key);
                if (! $app) {
                    return;
                }

                $tenant->appAccesses()->create([
                    'platform_app_id' => $app->id,
                    'status' => 'active',
                    'is_default' => $app->key === $defaultAppKey,
                    'metadata' => ['source' => 'platform_admin_onboarding'],
                ]);
            });

            if (! empty($validated['owner_email']) && $request->user()) {
                $temporaryPassword = $this->testingOwnerPassword((string) $validated['owner_email'], (string) $tenant->slug);
                $result = $this->tenantUsers->create(
                    $tenant,
                    (string) ($validated['owner_name'] ?: $validated['name']),
                    (string) $validated['owner_email'],
                    'owner',
                    $request->user(),
                    (string) $temporaryPassword,
                    (bool) ($validated['make_default'] ?? false),
                );
                $delivery = null;

                if (($validated['environment'] ?? null) === '01' && $result['created'] && $result['temporary_password']) {
                    $delivery = $this->temporaryPasswords->send(
                        $tenant,
                        $result['user'],
                        'owner',
                        (string) $result['temporary_password'],
                        'tenant_onboarding',
                    );
                }

                $ownerCredentials = [
                    'email' => $result['user']->email,
                    'name' => $result['user']->name,
                    'temporary_password' => ($validated['environment'] ?? null) === '01' ? null : $result['temporary_password'],
                    'temporary_password_delivery' => $delivery,
                    'must_change_password' => (bool) $result['user']->must_change_password,
                    'created' => $result['created'],
                ];
            } elseif ($request->user()) {
                $this->tenantUsers->create(
                    $tenant,
                    $request->user()->name,
                    $request->user()->email,
                    'owner',
                    $request->user(),
                    null,
                    (bool) ($validated['make_default'] ?? false),
                );
            }

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
                'owner' => $ownerCredentials,
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

    private function testingOwnerPassword(string $email, string $tenantSlug): string
    {
        $prefix = Str::upper(Str::substr((string) preg_replace('/[^A-Za-z0-9]/', '', Str::before($email, '@')), 0, 4)) ?: 'USER';
        $tenantCode = Str::upper(Str::substr((string) preg_replace('/[^A-Za-z0-9]/', '', $tenantSlug), 0, 4)) ?: 'TEMP';

        return 'Sf-'.$prefix.'-'.$tenantCode.'-'.random_int(1000, 9999);
    }
}

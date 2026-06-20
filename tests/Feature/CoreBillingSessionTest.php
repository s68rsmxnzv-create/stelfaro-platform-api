<?php

namespace Tests\Feature;

use App\Models\PlatformApp;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CoreBillingSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_billing_session_requires_authentication(): void
    {
        $this->get('https://taller.stelfaro.com/platform/core-billing-session')
            ->assertRedirect('https://platform.stelfaro.com/login');
    }

    public function test_core_billing_session_opens_core_session_for_platform_user(): void
    {
        config([
            'services.dte_core.base_url' => 'https://core.test/api/v1',
            'services.dte_core.internal_token' => 'internal-secret',
        ]);

        Http::fake([
            'https://core.test/api/v1/internal/auth/billing-session' => Http::response([
                'token' => 'core-token',
                'token_type' => 'Bearer',
                'expires_at' => null,
                'user' => [
                    'id' => 1,
                    'name' => 'Armando',
                    'email' => 'armando@example.test',
                ],
            ]),
        ]);

        $tenant = Tenant::query()->create([
            'slug' => 'cliente-demo',
            'name' => 'Cliente Demo',
            'metadata' => ['core_empresa_id' => 123],
        ]);
        $user = User::factory()->create([
            'email' => 'armando@example.test',
        ]);
        $user->memberships()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
            'is_default' => true,
        ]);

        $this->actingAs($user)
            ->getJson('https://taller.stelfaro.com/platform/core-billing-session')
            ->assertOk()
            ->assertJsonPath('token', 'core-token');

        Http::assertSent(fn ($request) => $request->url() === 'https://core.test/api/v1/internal/auth/billing-session'
            && $request->hasHeader('Authorization', 'Bearer internal-secret')
            && $request['email'] === 'armando@example.test'
            && $request['role'] === 'company_admin'
            && $request['platform_user_id'] === $user->id
            && $request['platform_tenant_id'] === $tenant->id
            && $request['platform_tenant_slug'] === 'cliente-demo'
            && $request['platform_tenant_name'] === 'Cliente Demo'
            && $request['empresas'][0]['id'] === 123);
    }

    public function test_platform_admin_core_proxy_uses_backoffice_core_session(): void
    {
        config([
            'platform.admin.fiscal_membership_roles' => ['platform_owner', 'fiscal_admin'],
            'services.dte_core.base_url' => 'https://core.test/api/v1',
            'services.dte_core.internal_token' => 'internal-secret',
            'services.dte_core.admin_email' => 'admin@stelfaro.com',
            'services.dte_core.admin_role' => 'admin_fiscal',
            'services.dte_core.admin_device_name' => 'stelfaro-platform-admin',
        ]);

        Http::fake([
            'https://core.test/api/v1/internal/auth/billing-session' => Http::response([
                'token' => 'backoffice-token',
                'token_type' => 'Bearer',
                'expires_at' => null,
            ]),
            'https://core.test/api/v1/health' => Http::response([
                'status' => 'ok',
                'service' => 'DTE Core',
            ]),
        ]);

        $user = User::factory()->create([
            'email' => 'owner@example.test',
        ]);
        $tenant = Tenant::query()->create([
            'slug' => 'platform-admin',
            'name' => 'Platform Admin',
        ]);
        $user->memberships()->create([
            'tenant_id' => $tenant->id,
            'role' => 'fiscal_admin',
            'status' => 'active',
            'is_default' => true,
        ]);

        $this->actingAs($user)
            ->getJson('https://platform.stelfaro.com/api/v1/admin/core/health')
            ->assertOk()
            ->assertJsonPath('service', 'DTE Core');

        Http::assertSent(fn ($request) => $request->url() === 'https://core.test/api/v1/internal/auth/billing-session'
            && $request->hasHeader('Authorization', 'Bearer internal-secret')
            && $request['email'] === 'admin@stelfaro.com'
            && $request['role'] === 'admin_fiscal'
            && $request['device_name'] === 'stelfaro-platform-admin');

        Http::assertSent(fn ($request) => $request->url() === 'https://core.test/api/v1/health'
            && $request->hasHeader('Authorization', 'Bearer backoffice-token'));
    }

    public function test_platform_admin_without_fiscal_scope_cannot_open_backoffice_core_proxy(): void
    {
        config([
            'platform.admin.platform_membership_roles' => ['platform_owner', 'platform_admin'],
            'platform.admin.fiscal_membership_roles' => ['platform_owner', 'fiscal_admin'],
        ]);
        PlatformApp::query()->create([
            'key' => 'facturacion',
            'name' => 'Facturación',
        ]);
        $tenant = Tenant::query()->create([
            'slug' => 'tenant-admin',
            'name' => 'Tenant Admin',
        ]);
        $user = User::factory()->create(['email' => 'admin@example.test']);
        $user->memberships()->create([
            'tenant_id' => $tenant->id,
            'role' => 'platform_admin',
            'status' => 'active',
            'is_default' => true,
        ]);

        $this->actingAs($user)
            ->getJson('https://platform.stelfaro.com/api/v1/admin/core/health')
            ->assertForbidden();
    }
}

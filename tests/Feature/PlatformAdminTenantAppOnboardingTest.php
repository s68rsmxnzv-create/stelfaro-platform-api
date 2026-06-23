<?php

namespace Tests\Feature;

use App\Models\PlatformApp;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlatformAdminTenantAppOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_active_platform_apps(): void
    {
        Config::set('platform.admin.emails', ['owner@stelfaro.test']);
        $user = User::factory()->create(['email' => 'owner@stelfaro.test']);
        PlatformApp::query()->create([
            'key' => 'facturacion',
            'name' => 'Facturación',
            'host' => 'facturacion.stelfaro.com',
        ]);
        PlatformApp::query()->create([
            'key' => 'taller',
            'name' => 'Taller',
            'host' => 'taller.stelfaro.com',
        ]);
        PlatformApp::query()->create([
            'key' => 'lab',
            'name' => 'Lab',
            'status' => 'inactive',
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/admin/platform/apps');

        $response->assertOk()
            ->assertJsonCount(2, 'apps')
            ->assertJsonPath('apps.0.key', 'facturacion')
            ->assertJsonPath('apps.0.is_core', true)
            ->assertJsonPath('apps.1.key', 'taller');
    }

    public function test_admin_onboarding_creates_tenant_with_taller_as_default_when_selected(): void
    {
        Config::set('platform.admin.emails', ['owner@stelfaro.test']);
        $this->fakeActiveCoreEmpresa(123);
        $user = User::factory()->create(['email' => 'owner@stelfaro.test']);
        $facturacion = PlatformApp::query()->create([
            'key' => 'facturacion',
            'name' => 'Facturación',
            'host' => 'facturacion.stelfaro.com',
        ]);
        $taller = PlatformApp::query()->create([
            'key' => 'taller',
            'name' => 'Taller',
            'host' => 'taller.stelfaro.com',
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/admin/platform/tenants', [
            'core_empresa_id' => 123,
            'core_tenant_id' => 456,
            'name' => 'Servicio Técnico El Faro',
            'app_keys' => ['facturacion', 'taller'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('tenant.slug', 'servicio-tecnico-el-faro')
            ->assertJsonCount(2, 'tenant.apps')
            ->assertJsonPath('tenant.apps.0.key', 'facturacion')
            ->assertJsonPath('tenant.apps.0.is_default', false)
            ->assertJsonPath('tenant.apps.1.key', 'taller')
            ->assertJsonPath('tenant.apps.1.is_default', true);

        $this->assertDatabaseHas('tenants', [
            'slug' => 'servicio-tecnico-el-faro',
            'primary_app_id' => $taller->id,
            'metadata->core_empresa_id' => 123,
            'metadata->core_tenant_id' => 456,
        ]);
        $this->assertDatabaseHas('tenant_app_accesses', [
            'tenant_id' => $response->json('tenant.id'),
            'platform_app_id' => $facturacion->id,
            'is_default' => false,
        ]);
        $this->assertDatabaseHas('tenant_app_accesses', [
            'tenant_id' => $response->json('tenant.id'),
            'platform_app_id' => $taller->id,
            'is_default' => true,
        ]);
        $this->assertDatabaseHas('user_tenant_memberships', [
            'user_id' => $user->id,
            'role' => 'owner',
            'is_default' => true,
        ]);
    }

    public function test_admin_onboarding_keeps_facturacion_as_default_when_it_is_the_only_app(): void
    {
        Config::set('platform.admin.emails', ['owner@stelfaro.test']);
        $this->fakeActiveCoreEmpresa(123);
        $user = User::factory()->create(['email' => 'owner@stelfaro.test']);
        $facturacion = PlatformApp::query()->create([
            'key' => 'facturacion',
            'name' => 'Facturación',
            'host' => 'facturacion.stelfaro.com',
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/admin/platform/tenants', [
            'core_empresa_id' => 123,
            'core_tenant_id' => 456,
            'name' => 'Facturación Libre Demo',
            'app_keys' => ['facturacion'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('tenant.slug', 'facturacion-libre-demo')
            ->assertJsonCount(1, 'tenant.apps')
            ->assertJsonPath('tenant.apps.0.key', 'facturacion')
            ->assertJsonPath('tenant.apps.0.is_default', true);

        $this->assertDatabaseHas('tenants', [
            'slug' => 'facturacion-libre-demo',
            'primary_app_id' => $facturacion->id,
            'metadata->core_empresa_id' => 123,
            'metadata->core_tenant_id' => 456,
        ]);
        $this->assertDatabaseHas('tenant_app_accesses', [
            'tenant_id' => $response->json('tenant.id'),
            'platform_app_id' => $facturacion->id,
            'is_default' => true,
        ]);
    }

    public function test_admin_onboarding_creates_company_owner_from_fiscal_email(): void
    {
        Config::set('platform.admin.emails', ['owner@stelfaro.test']);
        $this->fakeActiveCoreEmpresa(123);
        $admin = User::factory()->create(['email' => 'owner@stelfaro.test']);
        PlatformApp::query()->create([
            'key' => 'facturacion',
            'name' => 'Facturación',
            'host' => 'facturacion.stelfaro.com',
        ]);

        $response = $this->actingAs($admin)->postJson('/api/v1/admin/platform/tenants', [
            'core_empresa_id' => 123,
            'core_tenant_id' => 456,
            'name' => 'Gabriela Demo',
            'app_keys' => ['facturacion'],
            'owner_name' => 'Gabriela Demo',
            'owner_email' => 'gaby@stelfaro.com',
            'environment' => '00',
        ]);

        $response->assertCreated()
            ->assertJsonPath('tenant.owner.email', 'gaby@stelfaro.com')
            ->assertJsonPath('tenant.owner.must_change_password', true);

        $this->assertMatchesRegularExpression('/^Sf-GABY-GABR-[0-9]{4}$/', (string) $response->json('tenant.owner.temporary_password'));
        $owner = User::query()->where('email', 'gaby@stelfaro.com')->firstOrFail();

        $this->assertDatabaseHas('user_tenant_memberships', [
            'tenant_id' => $response->json('tenant.id'),
            'user_id' => $owner->id,
            'role' => 'owner',
            'status' => 'active',
        ]);
        $this->assertDatabaseMissing('user_tenant_memberships', [
            'tenant_id' => $response->json('tenant.id'),
            'user_id' => $admin->id,
        ]);
    }

    public function test_production_onboarding_sends_temporary_password_by_email_without_exposing_it(): void
    {
        Config::set('platform.admin.emails', ['owner@stelfaro.test']);
        config([
            'services.notifications.base_url' => 'https://notifications.test/api/v1',
            'services.notifications.internal_token' => 'notifications-secret',
        ]);
        $this->fakeActiveCoreEmpresa(123);
        $admin = User::factory()->create(['email' => 'owner@stelfaro.test']);
        PlatformApp::query()->create([
            'key' => 'facturacion',
            'name' => 'Facturación',
            'host' => 'facturacion.stelfaro.com',
        ]);

        Http::fake([
            'https://core.test/api/v1/internal/auth/billing-session' => Http::response([
                'token' => 'backoffice-token',
                'token_type' => 'Bearer',
                'expires_at' => null,
            ]),
            'https://core.test/api/v1/billing/context' => Http::response([
                'empresas' => [[
                    'id' => 123,
                    'nombre_comercial' => 'Empresa Validada',
                    'lifecycle_status' => 'active',
                ]],
            ]),
            'https://notifications.test/api/v1/platform/temporary-passwords/email' => Http::response(['data' => [
                'id' => 77,
                'status' => 'pending',
                'purpose' => 'platform_temporary_password',
                'recipient_email' => 'gaby@stelfaro.com',
            ]], 202),
        ]);

        $response = $this->actingAs($admin)->postJson('/api/v1/admin/platform/tenants', [
            'core_empresa_id' => 123,
            'core_tenant_id' => 456,
            'name' => 'Gabriela Produccion',
            'app_keys' => ['facturacion'],
            'owner_name' => 'Gabriela Produccion',
            'owner_email' => 'gaby@stelfaro.com',
            'environment' => '01',
        ]);

        $response->assertCreated()
            ->assertJsonPath('tenant.owner.email', 'gaby@stelfaro.com')
            ->assertJsonPath('tenant.owner.temporary_password', null)
            ->assertJsonPath('tenant.owner.temporary_password_delivery.id', 77);

        $this->assertDatabaseHas('tenants', [
            'id' => $response->json('tenant.id'),
            'metadata->environment' => '01',
        ]);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://notifications.test/api/v1/platform/temporary-passwords/email'
            && $request->hasHeader('Authorization', 'Bearer notifications-secret')
            && $request['recipient']['email'] === 'gaby@stelfaro.com'
            && str_starts_with((string) $request['temporary_password']['value'], 'Sf-GABY-GABR-')
            && $request['temporary_password']['reason'] === 'tenant_onboarding');
    }

    public function test_admin_onboarding_rejects_inactive_core_company_link(): void
    {
        Config::set('platform.admin.emails', ['owner@stelfaro.test']);
        $this->fakeActiveCoreEmpresa(999);
        $user = User::factory()->create(['email' => 'owner@stelfaro.test']);
        PlatformApp::query()->create([
            'key' => 'facturacion',
            'name' => 'Facturación',
            'host' => 'facturacion.stelfaro.com',
        ]);

        $this->actingAs($user)
            ->postJson('/api/v1/admin/platform/tenants', [
                'core_empresa_id' => 123,
                'name' => 'Empresa sin link fiscal',
                'app_keys' => ['facturacion'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('core_empresa_id');

        $this->assertDatabaseMissing('tenants', [
            'slug' => 'empresa-sin-link-fiscal',
        ]);
    }

    public function test_admin_onboarding_does_not_create_multiple_default_memberships(): void
    {
        Config::set('platform.admin.emails', ['owner@stelfaro.test']);
        $this->fakeActiveCoreEmpresa(123);
        $user = User::factory()->create(['email' => 'owner@stelfaro.test']);
        $existing = Tenant::query()->create([
            'slug' => 'tenant-existente',
            'name' => 'Tenant Existente',
            'metadata' => ['core_empresa_id' => 99],
        ]);
        $user->memberships()->create([
            'tenant_id' => $existing->id,
            'role' => 'owner',
            'status' => 'active',
            'is_default' => true,
        ]);
        PlatformApp::query()->create([
            'key' => 'facturacion',
            'name' => 'Facturación',
            'host' => 'facturacion.stelfaro.com',
        ]);

        $this->actingAs($user)
            ->postJson('/api/v1/admin/platform/tenants', [
                'core_empresa_id' => 123,
                'name' => 'Nuevo Tenant',
                'app_keys' => ['facturacion'],
            ])
            ->assertCreated();

        $this->assertSame(1, $user->memberships()->where('is_default', true)->count());
        $this->assertTrue($user->memberships()->where('tenant_id', $existing->id)->firstOrFail()->is_default);
    }

    public function test_admin_onboarding_can_replace_default_membership_when_requested(): void
    {
        Config::set('platform.admin.emails', ['owner@stelfaro.test']);
        $this->fakeActiveCoreEmpresa(123);
        $user = User::factory()->create(['email' => 'owner@stelfaro.test']);
        $existing = Tenant::query()->create([
            'slug' => 'tenant-antiguo',
            'name' => 'Tenant Antiguo',
            'metadata' => ['core_empresa_id' => 99],
        ]);
        $user->memberships()->create([
            'tenant_id' => $existing->id,
            'role' => 'owner',
            'status' => 'active',
            'is_default' => true,
        ]);
        PlatformApp::query()->create([
            'key' => 'facturacion',
            'name' => 'Facturación',
            'host' => 'facturacion.stelfaro.com',
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/v1/admin/platform/tenants', [
                'core_empresa_id' => 123,
                'name' => 'Tenant Nuevo',
                'app_keys' => ['facturacion'],
                'make_default' => true,
            ])
            ->assertCreated();

        $this->assertSame(1, $user->memberships()->where('is_default', true)->count());
        $this->assertFalse($user->memberships()->where('tenant_id', $existing->id)->firstOrFail()->is_default);
        $this->assertTrue($user->memberships()->where('tenant_id', $response->json('tenant.id'))->firstOrFail()->is_default);
    }

    public function test_non_admin_cannot_onboard_tenant_apps(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/admin/platform/tenants', [
                'core_empresa_id' => 123,
                'name' => 'Servicio Técnico El Faro',
                'app_keys' => ['facturacion'],
            ])
            ->assertForbidden();
    }

    private function fakeActiveCoreEmpresa(int $empresaId): void
    {
        config([
            'services.dte_core.base_url' => 'https://core.test/api/v1',
            'services.dte_core.internal_token' => 'internal-secret',
        ]);

        Http::fake([
            'https://core.test/api/v1/internal/auth/billing-session' => Http::response([
                'token' => 'backoffice-token',
                'token_type' => 'Bearer',
                'expires_at' => null,
            ]),
            'https://core.test/api/v1/billing/context' => Http::response([
                'empresas' => [[
                    'id' => $empresaId,
                    'nombre_comercial' => 'Empresa Validada',
                    'lifecycle_status' => 'active',
                ]],
            ]),
        ]);
    }
}

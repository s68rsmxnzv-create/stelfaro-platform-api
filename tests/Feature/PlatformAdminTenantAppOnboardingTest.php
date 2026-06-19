<?php

namespace Tests\Feature;

use App\Models\PlatformApp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
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
}

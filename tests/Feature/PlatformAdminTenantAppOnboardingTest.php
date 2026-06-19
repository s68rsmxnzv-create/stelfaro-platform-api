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

    public function test_admin_onboarding_creates_tenant_with_facturacion_forced(): void
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
            'app_keys' => ['taller'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('tenant.slug', 'servicio-tecnico-el-faro')
            ->assertJsonPath('tenant.apps.0.key', 'facturacion')
            ->assertJsonPath('tenant.apps.0.is_default', true)
            ->assertJsonPath('tenant.apps.1.key', 'taller');

        $this->assertDatabaseHas('tenants', [
            'slug' => 'servicio-tecnico-el-faro',
            'primary_app_id' => $facturacion->id,
        ]);
        $this->assertDatabaseHas('tenant_app_accesses', [
            'platform_app_id' => $facturacion->id,
            'is_default' => true,
        ]);
        $this->assertDatabaseHas('tenant_app_accesses', [
            'platform_app_id' => $taller->id,
            'is_default' => false,
        ]);
        $this->assertDatabaseHas('user_tenant_memberships', [
            'user_id' => $user->id,
            'role' => 'owner',
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

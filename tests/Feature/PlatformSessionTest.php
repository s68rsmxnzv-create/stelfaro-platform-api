<?php

namespace Tests\Feature;

use App\Models\PlatformApp;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_session_requires_authentication(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_platform_session_resolves_tenant_apps_and_default_redirect(): void
    {
        $taller = PlatformApp::query()->create([
            'key' => 'taller',
            'name' => 'Taller electrónico',
            'host' => 'taller.stelfaro.com',
            'default_path' => '/',
        ]);
        $facturacion = PlatformApp::query()->create([
            'key' => 'facturacion',
            'name' => 'Facturación',
            'host' => 'facturacion.stelfaro.com',
            'default_path' => '/',
        ]);
        $tenant = Tenant::query()->create([
            'slug' => 'servicio-tecnico-el-faro',
            'name' => 'Servicio Técnico El Faro',
            'primary_app_id' => $taller->id,
        ]);
        $tenant->appAccesses()->create([
            'platform_app_id' => $taller->id,
            'is_default' => true,
        ]);
        $tenant->appAccesses()->create([
            'platform_app_id' => $facturacion->id,
        ]);
        $user = User::factory()->create();
        $user->memberships()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
            'is_default' => true,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/me');

        $response->assertOk()
            ->assertJsonPath('tenant.slug', 'servicio-tecnico-el-faro')
            ->assertJsonPath('tenant.role', 'owner')
            ->assertJsonPath('apps.0.id', 'taller')
            ->assertJsonPath('apps.0.local_path', 'https://taller.stelfaro.com')
            ->assertJsonPath('apps.1.id', 'facturacion')
            ->assertJsonPath('default_app.id', 'taller')
            ->assertJsonPath('default_app.local_path', 'https://taller.stelfaro.com')
            ->assertJsonPath('redirect_url', 'https://taller.stelfaro.com/');
    }

    public function test_platform_session_handles_user_without_tenant(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/me');

        $response->assertOk()
            ->assertJsonPath('tenant', null)
            ->assertJsonPath('apps', [])
            ->assertJsonPath('default_app', null)
            ->assertJsonPath('redirect_url', null);
    }
}

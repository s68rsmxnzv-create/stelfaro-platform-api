<?php

namespace Tests\Feature;

use App\Models\PlatformApp;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_redirects_user_to_default_app(): void
    {
        $taller = PlatformApp::query()->create([
            'key' => 'taller',
            'name' => 'Taller electrónico',
            'host' => 'taller.stelfaro.com',
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
        $user = User::factory()->create();
        $user->memberships()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
            'is_default' => true,
        ]);

        $this->actingAs($user)
            ->get('https://platform.stelfaro.com/dashboard')
            ->assertRedirect('https://taller.stelfaro.com');
    }

    public function test_dashboard_redirects_platform_owner_to_admin_even_with_company_app(): void
    {
        $facturacion = PlatformApp::query()->create([
            'key' => 'facturacion',
            'name' => 'Facturación',
            'host' => 'facturacion.stelfaro.com',
            'default_path' => '/',
        ]);
        $tenant = Tenant::query()->create([
            'slug' => 'empresa-platform-owner',
            'name' => 'Empresa Platform Owner',
            'primary_app_id' => $facturacion->id,
        ]);
        $tenant->appAccesses()->create([
            'platform_app_id' => $facturacion->id,
            'is_default' => true,
        ]);
        $user = User::factory()->create([
            'platform_role' => 'platform_owner',
        ]);
        $user->memberships()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
            'is_default' => true,
        ]);

        $this->actingAs($user)
            ->get('https://platform.stelfaro.com/dashboard')
            ->assertRedirect('https://admin.stelfaro.com');
    }

    public function test_dashboard_forbids_user_when_user_has_no_apps(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('https://platform.stelfaro.com/dashboard')
            ->assertForbidden();
    }
}

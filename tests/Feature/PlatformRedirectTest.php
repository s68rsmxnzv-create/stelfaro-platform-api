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

    public function test_dashboard_returns_to_portal_when_user_has_no_apps(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('https://platform.stelfaro.com/dashboard')
            ->assertRedirect('https://platform.stelfaro.com');
    }
}

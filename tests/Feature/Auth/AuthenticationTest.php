<?php

namespace Tests\Feature\Auth;

use App\Models\PlatformApp;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Support\Header;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('https://platform.stelfaro.com/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->post('https://platform.stelfaro.com/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect('https://platform.stelfaro.com');
    }

    public function test_inertia_login_navigates_to_resolved_platform_destination(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->withHeader('X-Inertia', 'true')
            ->post('https://platform.stelfaro.com/login', [
                'email' => $user->email,
                'password' => 'password',
            ]);

        $this->assertAuthenticated();
        $response
            ->assertStatus(409)
            ->assertHeader(Header::LOCATION, 'https://platform.stelfaro.com');
    }

    public function test_inertia_login_navigates_to_default_app_subdomain(): void
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

        $response = $this
            ->withHeader('X-Inertia', 'true')
            ->post('https://platform.stelfaro.com/login', [
                'email' => $user->email,
                'password' => 'password',
            ]);

        $this->assertAuthenticated();
        $response
            ->assertStatus(409)
            ->assertHeader(Header::LOCATION, 'https://taller.stelfaro.com');
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('https://platform.stelfaro.com/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('https://platform.stelfaro.com/logout');

        $this->assertGuest();
        $response->assertRedirect('https://platform.stelfaro.com/login');
    }
}

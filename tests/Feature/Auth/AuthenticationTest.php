<?php

namespace Tests\Feature\Auth;

use App\Models\PlatformApp;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
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

    public function test_legacy_app_origins_redirect_login_to_the_canonical_origin(): void
    {
        $this->get('https://taller.stelfaro.com/login')->assertRedirect('https://platform.stelfaro.com/login');
        $this->get('https://facturacion.stelfaro.com/login')->assertRedirect('https://platform.stelfaro.com/login');
        $this->get('https://admin.stelfaro.com/login')->assertOk();
    }

    public function test_guest_is_redirected_to_login_on_the_canonical_origin(): void
    {
        $this->get('https://platform.stelfaro.com/taller/ordenes')
            ->assertRedirect('https://platform.stelfaro.com/login');

        $this->get('https://platform.stelfaro.com/facturacion/fe')
            ->assertRedirect('https://platform.stelfaro.com/login');
    }

    public function test_platform_sessions_expire_after_45_idle_minutes(): void
    {
        $this->assertSame(45, config('session.lifetime'));
    }

    public function test_platform_app_does_not_logout_on_pagehide(): void
    {
        $source = file_get_contents(base_path('resources/js/app.js'));

        $this->assertStringNotContainsString('pagehide', $source);
        $this->assertStringNotContainsString('sendBeacon', $source);
    }

    public function test_idle_platform_session_is_revoked_after_lifetime(): void
    {
        config([
            'services.dte_core.base_url' => 'https://core.test/api/v1',
            'services.dte_core.internal_token' => 'internal-secret',
        ]);
        Http::fake([
            'https://core.test/api/v1/internal/auth/billing-session/revoke' => Http::response([
                'revoked' => 1,
            ]),
        ]);

        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->withSession(['platform_last_activity_at' => now()->subMinutes(46)->timestamp])
            ->getJson('https://platform.stelfaro.com/api/v1/me');

        $response
            ->assertStatus(419)
            ->assertJson(['message' => 'Sesión expirada por inactividad.']);

        $this->assertGuest();
        Http::assertSent(fn ($request) => $request->url() === 'https://core.test/api/v1/internal/auth/billing-session/revoke'
            && $request->hasHeader('Authorization', 'Bearer internal-secret')
            && is_string($request['platform_session_id'] ?? null)
            && $request['platform_session_id'] !== '');
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

    public function test_logout_stays_on_the_installed_app_origin(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('https://taller.stelfaro.com/logout')
            ->assertRedirect('https://taller.stelfaro.com/login');

        $this->assertGuest();
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
            ->assertHeader(Header::LOCATION, 'https://platform.stelfaro.com/taller/');
    }

    public function test_platform_owner_login_prefers_company_default_app_when_assigned(): void
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

        $response = $this
            ->withHeader('X-Inertia', 'true')
            ->post('https://platform.stelfaro.com/login', [
                'email' => $user->email,
                'password' => 'password',
            ]);

        $this->assertAuthenticated();
        $response
            ->assertStatus(409)
            ->assertHeader(Header::LOCATION, 'https://platform.stelfaro.com/facturacion/');
    }

    public function test_platform_owner_login_uses_admin_when_no_company_app_exists(): void
    {
        $user = User::factory()->create([
            'platform_role' => 'platform_owner',
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
            ->assertHeader(Header::LOCATION, 'https://platform.stelfaro.com/administracion/');
    }

    public function test_temporary_password_user_must_change_password_after_login(): void
    {
        auth()->guard('web')->logout();

        $user = User::factory()->create([
            'password' => Hash::make('Temporal123'),
            'must_change_password' => true,
        ]);
        $this->assertTrue($user->fresh()->must_change_password);

        $response = $this
            ->withHeader('X-Inertia', 'true')
            ->post('https://platform.stelfaro.com/login', [
                'email' => $user->email,
                'password' => 'Temporal123',
            ]);

        $this->assertAuthenticated();
        $response
            ->assertStatus(409)
            ->assertHeader(Header::LOCATION, 'https://platform.stelfaro.com/change-temporary-password');

        $this->flushHeaders()
            ->actingAs($user->fresh())
            ->get('https://platform.stelfaro.com')
            ->assertRedirect('https://platform.stelfaro.com/change-temporary-password');
    }

    public function test_temporary_password_can_be_changed(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('Temporal123'),
            'must_change_password' => true,
        ]);

        $this->actingAs($user)
            ->put('https://platform.stelfaro.com/change-temporary-password', [
                'current_password' => 'Temporal123',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertRedirect('https://platform.stelfaro.com');

        $user->refresh();

        $this->assertFalse($user->must_change_password);
        $this->assertNotNull($user->password_changed_at);
        $this->assertTrue(Hash::check('new-password', $user->password));
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('https://platform.stelfaro.com/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
        $this->assertDatabaseHas('security_events', [
            'type' => 'auth.login_failed',
            'severity' => 'warning',
            'field' => 'email',
        ]);
    }

    public function test_users_can_logout(): void
    {
        config([
            'services.dte_core.base_url' => 'https://core.test/api/v1',
            'services.dte_core.internal_token' => 'internal-secret',
        ]);
        Http::fake([
            'https://core.test/api/v1/internal/auth/billing-session/revoke' => Http::response([
                'revoked' => 1,
            ]),
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('https://platform.stelfaro.com/logout');

        $this->assertGuest();
        $response->assertRedirect('https://platform.stelfaro.com/login');

        Http::assertSent(fn ($request) => $request->url() === 'https://core.test/api/v1/internal/auth/billing-session/revoke'
            && $request->hasHeader('Authorization', 'Bearer internal-secret')
            && is_string($request['platform_session_id'] ?? null)
            && $request['platform_session_id'] !== '');
    }

    public function test_explicit_api_logout_revokes_core_session(): void
    {
        config([
            'services.dte_core.base_url' => 'https://core.test/api/v1',
            'services.dte_core.internal_token' => 'internal-secret',
        ]);
        Http::fake([
            'https://core.test/api/v1/internal/auth/billing-session/revoke' => Http::response([
                'revoked' => 1,
            ]),
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('https://platform.stelfaro.com/api/v1/logout');

        $this->assertGuest();
        $response->assertRedirect('https://platform.stelfaro.com/login');

        Http::assertSent(fn ($request) => $request->url() === 'https://core.test/api/v1/internal/auth/billing-session/revoke'
            && $request->hasHeader('Authorization', 'Bearer internal-secret')
            && is_string($request['platform_session_id'] ?? null)
            && $request['platform_session_id'] !== '');
    }
}

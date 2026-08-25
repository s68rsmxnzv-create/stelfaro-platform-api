<?php

namespace Tests\Feature;

use App\Models\PlatformApp;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InertiaPlatformPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_entry_redirects_to_default_app(): void
    {
        $taller = PlatformApp::query()->create([
            'key' => 'taller',
            'name' => 'Taller electrónico',
            'host' => 'new.stelfaro.com',
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
            ->get('https://new.stelfaro.com')
            ->assertRedirect('https://new.stelfaro.com/taller/');
    }

    public function test_taller_page_renders(): void
    {
        $this->actingAs($this->userWithApp('taller'))
            ->get('https://new.stelfaro.com/taller')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Taller/BillingWorkspace')
                ->where('app.id', 'taller')
                ->where('module', 'dashboard')
            );
    }

    public function test_platform_invitation_accept_page_renders(): void
    {
        $tenant = Tenant::query()->create([
            'slug' => 'cliente-demo',
            'name' => 'Cliente Demo',
        ]);
        $token = 'invitation-token';
        UserInvitation::query()->create([
            'tenant_id' => $tenant->id,
            'email' => 'contador@example.test',
            'role' => 'viewer',
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDay(),
            'status' => 'pending',
        ]);
        $user = User::factory()->create(['email' => 'contador@example.test']);

        $this->actingAs($user)
            ->get("https://new.stelfaro.com/invitations/{$token}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Invitations/Accept')
                ->where('invitation.email', 'contador@example.test')
                ->where('invitation.role', 'viewer')
                ->where('invitation.tenant.name', 'Cliente Demo')
                ->where('user.email', 'contador@example.test')
            );
    }

    public function test_platform_invitation_accept_page_renders_for_guests(): void
    {
        $tenant = Tenant::query()->create([
            'slug' => 'cliente-demo',
            'name' => 'Cliente Demo',
        ]);
        $token = 'guest-invitation-token';
        UserInvitation::query()->create([
            'tenant_id' => $tenant->id,
            'email' => 'contador@example.test',
            'role' => 'viewer',
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDay(),
            'status' => 'pending',
        ]);

        $this
            ->get("https://new.stelfaro.com/invitations/{$token}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Invitations/Accept')
                ->where('invitation.email', 'contador@example.test')
                ->where('invitation.tenant.name', 'Cliente Demo')
                ->where('user', null)
            );
    }

    public function test_facturacion_page_renders(): void
    {
        $this->actingAs($this->userWithApp('facturacion'))
            ->get('https://new.stelfaro.com/facturacion')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Taller/BillingWorkspace')
                ->where('app.id', 'facturacion')
                ->where('module', 'dashboard')
            );
    }

    public function test_taller_user_keeps_workshop_context_when_opening_billing_url_directly(): void
    {
        $user = $this->userWithApps(['facturacion', 'taller'], 'taller');

        $this->actingAs($user)
            ->get('https://new.stelfaro.com/facturacion')
            ->assertRedirect('https://new.stelfaro.com/taller/');

        $this->actingAs($user)
            ->get('https://new.stelfaro.com/facturacion/ccf?cliente=25')
            ->assertRedirect('https://new.stelfaro.com/taller/facturacion/ccf?cliente=25');

        $this->actingAs($user)
            ->get('https://new.stelfaro.com/facturacion/clientes')
            ->assertRedirect('https://new.stelfaro.com/taller/clientes');
    }

    public function test_commercial_orders_are_available_in_billing_and_workshop_apps(): void
    {
        $this->actingAs($this->userWithApp('facturacion'))
            ->get('https://new.stelfaro.com/facturacion/ordenes-trabajo')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Taller/BillingWorkspace')
                ->where('app.id', 'facturacion')
                ->where('module', 'commercial-orders')
            );

        $this->actingAs($this->userWithApp('taller'))
            ->get('https://new.stelfaro.com/taller/ordenes-trabajo')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Taller/BillingWorkspace')
                ->where('app.id', 'taller')
                ->where('module', 'commercial-orders')
            );
    }

    public function test_iva_books_placeholder_is_available_in_billing_and_workshop_apps(): void
    {
        $portalHost = config('platform.portal.host');

        $this->actingAs($this->userWithApp('facturacion'))
            ->get("https://{$portalHost}/facturacion/libros-iva")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Taller/BillingWorkspace')
                ->where('app.id', 'facturacion')
                ->where('module', 'iva-books')
            );

        $this->actingAs($this->userWithApp('taller'))
            ->get("https://{$portalHost}/taller/libros-iva")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Taller/BillingWorkspace')
                ->where('app.id', 'taller')
                ->where('module', 'iva-books')
            );
    }

    public function test_taller_billing_page_renders_workspace(): void
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
            ]),
        ]);

        $tenant = Tenant::query()->create([
            'slug' => 'servicio-tecnico-el-faro',
            'name' => 'Servicio Técnico El Faro',
            'metadata' => ['core_empresa_id' => 123],
        ]);
        $taller = PlatformApp::query()->create([
            'key' => 'taller',
            'name' => 'Taller electrónico',
            'host' => 'new.stelfaro.com',
            'default_path' => '/',
        ]);
        $tenant->appAccesses()->create([
            'platform_app_id' => $taller->id,
            'status' => 'active',
            'is_default' => true,
        ]);
        $user = User::factory()->create();
        $user->memberships()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
            'is_default' => true,
        ]);

        $this->actingAs($user)
            ->get('https://new.stelfaro.com/taller/facturacion')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Taller/BillingWorkspace')
                ->where('app.id', 'taller')
                ->where('module', 'billing')
                ->where('coreBaseUrl', '/core-api/v1')
                ->where('coreSession.token', 'core-token')
            );
    }

    public function test_taller_reuses_billing_package_modules(): void
    {
        $user = $this->userWithApp('taller');

        $this->actingAs($user)
            ->get('https://new.stelfaro.com/taller/comprobantes')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Taller/BillingWorkspace')
                ->where('module', 'artifacts')
            );

        $this->actingAs($user)
            ->get('https://new.stelfaro.com/taller/eventos-mh/contingencia')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Taller/BillingWorkspace')
                ->where('module', 'mh-events')
                ->where('eventSlug', 'contingencia')
            );

        $this->actingAs($user)
            ->get('https://new.stelfaro.com/taller/catalogo')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Taller/BillingWorkspace')
                ->where('module', 'catalog')
            );

        $this->actingAs($user)
            ->get('https://new.stelfaro.com/taller/configuracion-fiscal')
            ->assertRedirect('https://new.stelfaro.com/taller/configuracion');

        $this->actingAs($user)
            ->get('https://new.stelfaro.com/taller/configuracion')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Taller/BillingWorkspace')
                ->where('module', 'settings')
            );
    }

    public function test_facturacion_only_user_is_redirected_away_from_taller(): void
    {
        $user = $this->userWithApp('facturacion');

        $this->actingAs($user)
            ->get('https://new.stelfaro.com/taller/ordenes')
            ->assertRedirect('https://new.stelfaro.com/facturacion/');
    }

    private function userWithApp(string $appKey): User
    {
        $app = PlatformApp::query()->firstOrCreate(
            ['key' => $appKey],
            [
                'name' => $appKey === 'taller' ? 'Taller electrónico' : 'Facturación',
                'host' => $appKey.'.stelfaro.com',
                'default_path' => '/',
                'status' => 'active',
            ],
        );
        $tenant = Tenant::query()->create([
            'slug' => fake()->unique()->slug(2),
            'name' => fake()->company(),
            'primary_app_id' => $app->id,
        ]);
        $tenant->appAccesses()->create([
            'platform_app_id' => $app->id,
            'status' => 'active',
            'is_default' => true,
        ]);
        $user = User::factory()->create();
        $user->memberships()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
            'status' => 'active',
            'is_default' => true,
        ]);

        return $user;
    }

    /**
     * @param  array<int, string>  $appKeys
     */
    private function userWithApps(array $appKeys, string $defaultAppKey): User
    {
        $apps = collect($appKeys)->mapWithKeys(function (string $appKey): array {
            $app = PlatformApp::query()->firstOrCreate(
                ['key' => $appKey],
                [
                    'name' => $appKey === 'taller' ? 'Taller electrónico' : 'Facturación',
                    'host' => $appKey.'.stelfaro.com',
                    'default_path' => '/',
                    'status' => 'active',
                ],
            );

            return [$appKey => $app];
        });

        $tenant = Tenant::query()->create([
            'slug' => fake()->unique()->slug(2),
            'name' => fake()->company(),
            'primary_app_id' => $apps->get($defaultAppKey)?->id,
        ]);

        $apps->each(function (PlatformApp $app, string $appKey) use ($tenant, $defaultAppKey): void {
            $tenant->appAccesses()->create([
                'platform_app_id' => $app->id,
                'status' => 'active',
                'is_default' => $appKey === $defaultAppKey,
            ]);
        });

        $user = User::factory()->create();
        $user->memberships()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
            'status' => 'active',
            'is_default' => true,
        ]);

        return $user;
    }
}

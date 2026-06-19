<?php

namespace Tests\Feature;

use App\Models\PlatformApp;
use App\Models\Tenant;
use App\Models\User;
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
            ->get('https://platform.stelfaro.com')
            ->assertRedirect('https://taller.stelfaro.com');
    }

    public function test_taller_page_renders(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('https://taller.stelfaro.com')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Taller/BillingWorkspace')
                ->where('app.id', 'taller')
                ->where('module', 'dashboard')
            );
    }

    public function test_facturacion_page_renders(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('https://facturacion.stelfaro.com')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Taller/BillingWorkspace')
                ->where('app.id', 'facturacion')
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
        $user = User::factory()->create();
        $user->memberships()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
            'is_default' => true,
        ]);

        $this->actingAs($user)
            ->get('https://taller.stelfaro.com/facturacion')
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
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('https://taller.stelfaro.com/comprobantes')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Taller/BillingWorkspace')
                ->where('module', 'artifacts')
            );

        $this->actingAs($user)
            ->get('https://taller.stelfaro.com/eventos-mh/contingencia')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Taller/BillingWorkspace')
                ->where('module', 'mh-events')
                ->where('eventSlug', 'contingencia')
            );

        $this->actingAs($user)
            ->get('https://taller.stelfaro.com/configuracion-fiscal')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Taller/BillingWorkspace')
                ->where('module', 'settings')
            );
    }
}

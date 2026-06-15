<?php

namespace Tests\Feature;

use App\Models\PlatformApp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InertiaPlatformPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_page_renders_available_apps(): void
    {
        PlatformApp::query()->create([
            'key' => 'taller',
            'name' => 'Taller electrónico',
            'host' => 'taller.stelfaro.com',
            'default_path' => '/',
        ]);

        $this->actingAs(User::factory()->create())
            ->get('https://platform.stelfaro.com')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Portal/Home')
                ->has('availableApps', 1)
                ->where('availableApps.0.id', 'taller')
            );
    }

    public function test_taller_page_renders(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('https://taller.stelfaro.com')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Taller/Dashboard')
                ->where('app.id', 'taller')
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
            'services.dte_core.bridge_password' => 'secret',
        ]);
        Http::fake([
            'https://core.test/api/v1/auth/login' => Http::response([
                'token' => 'core-token',
                'token_type' => 'Bearer',
                'expires_at' => null,
            ]),
        ]);

        $this->actingAs(User::factory()->create())
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

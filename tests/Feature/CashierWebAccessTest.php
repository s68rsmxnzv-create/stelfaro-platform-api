<?php

namespace Tests\Feature;

use App\Models\PlatformApp;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Platform\PlatformRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CashierWebAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_billing_user_maps_to_cashier_without_changing_seller(): void
    {
        $this->assertSame(PlatformRoles::FISCAL_CASHIER, PlatformRoles::fiscalRoleForTenantRole(PlatformRoles::BILLING_USER));
        $this->assertSame(PlatformRoles::FISCAL_BILLING_USER, PlatformRoles::fiscalRoleForTenantRole(PlatformRoles::SELLER));
        $this->assertSame(PlatformRoles::FISCAL_COMPANY_ADMIN, PlatformRoles::fiscalRoleForTenantRole(PlatformRoles::BILLING_ADMIN));
        $this->assertSame(PlatformRoles::FISCAL_VIEWER, PlatformRoles::fiscalRoleForTenantRole(PlatformRoles::ACCOUNTANT));
    }

    public function test_cashier_uses_the_traditional_billing_workspace(): void
    {
        $user = $this->cashierWithBillingApp();
        $host = (string) config('platform.portal.host');

        // El cajero entra al facturador tradicional: sólo se le desvía del
        // dashboard fiscal (que no puede ver) al facturador. El endurecimiento
        // real vive en la API (dte-core devuelve 403) y en el gating de UI por
        // rol fiscal.
        $this->actingAs($user)
            ->get("https://{$host}/facturacion")
            ->assertRedirect("https://{$host}/facturacion/fe");

        $this->actingAs($user)
            ->get("https://{$host}/facturacion/fe")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Taller/BillingWorkspace')
                ->where('app.id', 'facturacion')
                ->where('module', 'billing')
            );
    }

    private function cashierWithBillingApp(): User
    {
        $app = PlatformApp::query()->create([
            'key' => 'facturacion',
            'name' => 'Facturación',
            'host' => 'new.stelfaro.com',
            'default_path' => '/',
            'status' => 'active',
        ]);
        $tenant = Tenant::query()->create([
            'slug' => 'cashier-web',
            'name' => 'Empresa Cajero',
            'status' => 'active',
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
            'role' => PlatformRoles::BILLING_USER,
            'status' => 'active',
            'is_default' => true,
        ]);

        return $user;
    }
}

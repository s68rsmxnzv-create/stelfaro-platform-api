<?php

namespace Tests\Feature;

use App\Models\CatalogItem;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_category_and_catalog_item(): void
    {
        [$owner, $tenant] = $this->userWithTenantRole('owner');

        $categoryId = $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/catalog/categories", [
                'name' => 'Repuestos',
                'kind' => 'product',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Repuestos')
            ->json('data.id');

        $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/catalog/items", [
                'catalog_category_id' => $categoryId,
                'sku' => 'REP-001',
                'name' => 'Pantalla OLED',
                'item_type' => 'part',
                'controls_inventory' => true,
                'base_price' => 55.50,
                'reference_cost' => 30.25,
            ])
            ->assertCreated()
            ->assertJsonPath('data.sku', 'REP-001')
            ->assertJsonPath('data.controls_inventory', true)
            ->assertJsonPath('data.cost_source', 'reference')
            ->assertJsonPath('data.stock_quantity', 0);

        $this->assertDatabaseHas('catalog_items', [
            'tenant_id' => $tenant->id,
            'sku' => 'REP-001',
            'controls_inventory' => true,
            'stock_quantity' => 0,
        ]);
    }

    public function test_service_items_are_forced_to_catalog_only(): void
    {
        [$owner, $tenant] = $this->userWithTenantRole('owner');

        $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/catalog/items", [
                'sku' => 'SERV-001',
                'name' => 'Diagnóstico técnico',
                'item_type' => 'service',
                'controls_inventory' => true,
                'base_price' => 15,
            ])
            ->assertCreated()
            ->assertJsonPath('data.controls_inventory', false);
    }

    public function test_catalog_item_generates_sku_when_missing(): void
    {
        [$owner, $tenant] = $this->userWithTenantRole('owner');

        $categoryId = $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/catalog/categories", [
                'name' => 'Lubricantes',
                'kind' => 'product',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/catalog/items", [
                'catalog_category_id' => $categoryId,
                'name' => 'Aceite 10W30',
                'item_type' => 'product',
            ])
            ->assertCreated()
            ->assertJsonPath('data.sku', 'LUBR-ACEI10W3-001');
    }

    public function test_billing_user_can_view_but_cannot_manage_catalog(): void
    {
        [$owner, $tenant] = $this->userWithTenantRole('owner');
        [$billingUser] = $this->userWithTenantRole('billing_user', $tenant);

        CatalogItem::query()->create([
            'tenant_id' => $tenant->id,
            'sku' => 'CAT-001',
            'name' => 'Servicio sin inventario',
            'item_type' => 'service',
            'controls_inventory' => false,
            'base_price' => 10,
        ]);

        $this->actingAs($billingUser)
            ->getJson("/api/v1/platform/tenants/{$tenant->id}/catalog/items")
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Servicio sin inventario');

        $this->actingAs($billingUser)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/catalog/items", [
                'sku' => 'NOPE',
                'name' => 'No permitido',
                'item_type' => 'product',
            ])
            ->assertForbidden();

        $this->actingAs($owner)
            ->getJson("/api/v1/platform/tenants/{$tenant->id}/catalog/items")
            ->assertOk();
    }

    public function test_user_cannot_access_other_tenant_catalog(): void
    {
        [$user] = $this->userWithTenantRole('owner');
        $otherTenant = Tenant::query()->create([
            'slug' => 'otra-empresa',
            'name' => 'Otra Empresa',
        ]);

        $this->actingAs($user)
            ->getJson("/api/v1/platform/tenants/{$otherTenant->id}/catalog/items")
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: Tenant}
     */
    private function userWithTenantRole(string $role, ?Tenant $tenant = null): array
    {
        $tenant ??= Tenant::query()->create([
            'slug' => 'tenant-'.strtolower($role).'-'.Str::random(6),
            'name' => 'Tenant '.$role,
        ]);

        $user = User::factory()->create();
        $user->memberships()->create([
            'tenant_id' => $tenant->id,
            'role' => $role,
            'status' => 'active',
            'is_default' => true,
        ]);

        return [$user, $tenant];
    }
}

<?php

namespace Tests\Feature;

use App\Models\CatalogItem;
use App\Models\InventoryLot;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CashierInventoryAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_is_blocked_from_cost_reports_but_admin_is_not(): void
    {
        [$admin, $tenant] = $this->userWithTenantRole('company_admin');
        [$cashier] = $this->userWithTenantRole('billing_user', $tenant);
        $base = "/api/v1/platform/tenants/{$tenant->id}/inventory";

        $this->actingAs($cashier)->getJson($base.'/reports/margin')->assertForbidden();
        $this->actingAs($cashier)->getJson($base.'/reports/sales')->assertForbidden();
        $this->actingAs($cashier)->getJson($base.'/reports/kardex')->assertForbidden();

        $this->actingAs($admin)->getJson($base.'/reports/margin')->assertOk();
    }

    public function test_cashier_summary_and_catalog_omit_cost_fields(): void
    {
        [$admin, $tenant] = $this->userWithTenantRole('company_admin');
        [$cashier] = $this->userWithTenantRole('billing_user', $tenant);
        $item = $this->inventoryItem($tenant, 'CJ-001');
        $this->lot($tenant, $item, 'CJ-LOT', '2026-08-01', 6, 4.5, [
            'core_sucursal_id' => 10,
            'core_sucursal_code' => 'S01',
            'core_sucursal_name' => 'Casa Matriz',
        ]);
        $item->forceFill(['stock_quantity' => 6, 'reference_cost' => 4.5, 'cost_source' => 'real'])->save();

        $base = "/api/v1/platform/tenants/{$tenant->id}/inventory";

        $summary = $this->actingAs($cashier)->getJson($base.'/reports/summary')->assertOk()->json('data');
        $this->assertArrayNotHasKey('inventory_value', $summary);
        $this->assertArrayNotHasKey('stock_value', $summary['stock_by_item'][0]);

        $items = $this->actingAs($cashier)
            ->getJson("/api/v1/platform/tenants/{$tenant->id}/catalog/items")
            ->assertOk()
            ->json('data');
        $this->assertNull($items[0]['reference_cost']);
        $this->assertSame('none', $items[0]['cost_source']);

        $adminItems = $this->actingAs($admin)
            ->getJson("/api/v1/platform/tenants/{$tenant->id}/catalog/items")
            ->assertOk()
            ->json('data');
        $this->assertSame(4.5, $adminItems[0]['reference_cost']);
    }

    public function test_cashier_reads_stock_by_branch_without_cost(): void
    {
        [$owner, $tenant] = $this->userWithTenantRole('owner');
        [$cashier] = $this->userWithTenantRole('billing_user', $tenant);
        $item = $this->inventoryItem($tenant, 'CJ-002');
        $this->lot($tenant, $item, 'CJ-LOT-A', '2026-08-01', 4, 3.0, [
            'core_sucursal_id' => 10, 'core_sucursal_code' => 'S01', 'core_sucursal_name' => 'Casa Matriz',
        ]);
        $this->lot($tenant, $item, 'CJ-LOT-B', '2026-08-02', 7, 3.0, [
            'core_sucursal_id' => 20, 'core_sucursal_code' => 'S02', 'core_sucursal_name' => 'Sucursal Norte',
        ]);

        $data = $this->actingAs($cashier)
            ->getJson("/api/v1/platform/tenants/{$tenant->id}/inventory/stock-by-branch")
            ->assertOk()
            ->json('data.items');

        $this->assertCount(1, $data);
        $this->assertEqualsWithDelta(11.0, $data[0]['total'], 0.001);
        $this->assertCount(2, $data[0]['by_branch']);
        $encoded = json_encode($data);
        $this->assertStringNotContainsStringIgnoringCase('cost', $encoded);
        $this->assertStringNotContainsStringIgnoringCase('costo', $encoded);
    }

    private function userWithTenantRole(string $role, ?Tenant $tenant = null): array
    {
        $tenant ??= Tenant::query()->create([
            'slug' => 'tenant-'.strtolower(str_replace('_', '-', $role)).'-'.Str::random(6),
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

    private function inventoryItem(Tenant $tenant, string $sku): CatalogItem
    {
        return CatalogItem::query()->create([
            'tenant_id' => $tenant->id,
            'sku' => $sku,
            'name' => 'Item '.$sku,
            'item_type' => 'part',
            'controls_inventory' => true,
            'base_price' => 25,
            'stock_quantity' => 0,
            'status' => 'active',
        ]);
    }

    private function lot(Tenant $tenant, CatalogItem $item, string $code, string $date, float $quantity, float $cost, array $extra = []): InventoryLot
    {
        return InventoryLot::query()->create([
            'tenant_id' => $tenant->id,
            ...$extra,
            'catalog_item_id' => $item->id,
            'lot_code' => $code,
            'received_date' => $date,
            'unit_cost' => $cost,
            'initial_quantity' => $quantity,
            'available_quantity' => $quantity,
            'status' => 'active',
        ]);
    }
}

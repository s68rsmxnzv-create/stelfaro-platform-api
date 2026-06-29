<?php

namespace Tests\Feature;

use App\Models\CatalogItem;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventorySupplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_creates_lot_entry_movement_and_updates_stock(): void
    {
        [$owner, $tenant] = $this->userWithTenantRole('owner');
        $item = $this->inventoryItem($tenant, 'PAN-001');

        $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/purchases", [
                'purchase_date' => '2026-06-29',
                'document_number' => 'CCF-1',
                'lines' => [
                    ['catalog_item_id' => $item->id, 'quantity' => 5, 'unit_cost' => 12.5],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.total', '62.50');

        $item->refresh();
        $this->assertSame(5.0, (float) $item->stock_quantity);
        $this->assertSame(12.5, (float) $item->reference_cost);
        $this->assertDatabaseHas('inventory_lots', [
            'tenant_id' => $tenant->id,
            'catalog_item_id' => $item->id,
            'available_quantity' => 5,
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'tenant_id' => $tenant->id,
            'catalog_item_id' => $item->id,
            'movement_type' => 'entry',
            'reason' => 'purchase',
        ]);
    }

    public function test_reservation_splits_fifo_and_confirm_creates_sale_movements(): void
    {
        [$owner, $tenant] = $this->userWithTenantRole('owner');
        $item = $this->inventoryItem($tenant, 'BAT-001');
        $this->lot($tenant, $item, 'L-A', '2026-01-01', 1, 10);
        $this->lot($tenant, $item, 'L-B', '2026-01-02', 2, 15);
        $item->forceFill(['stock_quantity' => 3, 'reference_cost' => 13.3333, 'cost_source' => 'real'])->save();

        $reserve = $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/reservations", [
                'idempotency_key' => 'issue-1',
                'lines' => [
                    ['catalog_item_id' => $item->id, 'quantity' => 2, 'description' => 'Bateria'],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'reserved')
            ->json('data');

        $item->refresh();
        $this->assertSame(1.0, (float) $item->stock_quantity);
        $this->assertDatabaseHas('inventory_sale_allocations', ['inventory_lot_id' => InventoryLot::query()->where('lot_code', 'L-A')->value('id'), 'quantity' => 1]);
        $this->assertDatabaseHas('inventory_sale_allocations', ['inventory_lot_id' => InventoryLot::query()->where('lot_code', 'L-B')->value('id'), 'quantity' => 1]);

        $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/reservations/{$reserve['id']}/confirm", [
                'source_type' => 'dte',
                'source_id' => '77',
                'source_number' => 'DTE-01-M001P001-000000000000077',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        $salidas = InventoryMovement::query()
            ->where('catalog_item_id', $item->id)
            ->where('movement_type', 'exit')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $salidas);
        $this->assertSame(10.0, (float) $salidas[0]->unit_cost);
        $this->assertSame(15.0, (float) $salidas[1]->unit_cost);
    }

    public function test_release_restores_reserved_stock(): void
    {
        [$owner, $tenant] = $this->userWithTenantRole('owner');
        $item = $this->inventoryItem($tenant, 'CAM-001');
        $lot = $this->lot($tenant, $item, 'L-C', '2026-01-01', 3, 8);
        $item->forceFill(['stock_quantity' => 3, 'reference_cost' => 8, 'cost_source' => 'real'])->save();

        $reserve = $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/reservations", [
                'idempotency_key' => 'issue-release',
                'lines' => [
                    ['catalog_item_id' => $item->id, 'quantity' => 2],
                ],
            ])
            ->assertCreated()
            ->json('data');

        $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/reservations/{$reserve['id']}/release")
            ->assertOk()
            ->assertJsonPath('data.status', 'released');

        $item->refresh();
        $lot->refresh();
        $this->assertSame(3.0, (float) $item->stock_quantity);
        $this->assertSame(3.0, (float) $lot->available_quantity);
    }

    public function test_reverse_confirmed_reservation_restores_lots_and_creates_entry_movement(): void
    {
        [$owner, $tenant] = $this->userWithTenantRole('owner');
        $item = $this->inventoryItem($tenant, 'REV-001');
        $lot = $this->lot($tenant, $item, 'L-REV', '2026-01-01', 2, 11);
        $item->forceFill(['stock_quantity' => 2, 'reference_cost' => 11, 'cost_source' => 'real'])->save();

        $reserve = $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/reservations", [
                'idempotency_key' => 'issue-reverse',
                'lines' => [
                    ['catalog_item_id' => $item->id, 'quantity' => 2],
                ],
            ])
            ->assertCreated()
            ->json('data');

        $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/reservations/{$reserve['id']}/confirm", [
                'source_type' => 'dte',
                'source_id' => '88',
                'source_number' => 'DTE-88',
            ])
            ->assertOk();

        $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/reservations/{$reserve['id']}/reverse", [
                'source_type' => 'annulment',
                'source_id' => 'ANU-88',
                'source_number' => 'INVALIDACION-88',
                'notes' => 'Anulacion fiscal',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'reversed');

        $item->refresh();
        $lot->refresh();
        $this->assertSame(2.0, (float) $item->stock_quantity);
        $this->assertSame(2.0, (float) $lot->available_quantity);
        $this->assertDatabaseHas('inventory_movements', [
            'tenant_id' => $tenant->id,
            'catalog_item_id' => $item->id,
            'movement_type' => 'entry',
            'reason' => 'reversal',
            'reference_type' => 'annulment',
        ]);
    }

    public function test_dte_json_import_preview_matches_supplier_lines_and_fuel_charges(): void
    {
        [$owner, $tenant] = $this->userWithTenantRole('owner');
        InventorySupplier::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Proveedor Uno',
            'tax_id' => '0614-010101-101-1',
            'status' => 'active',
        ]);
        $item = $this->inventoryItem($tenant, 'GAS-001');
        $item->forceFill(['name' => 'Gasolina regular', 'unit_code' => '22'])->save();

        $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/purchases/import-dte-json", [
                'payload' => $this->supplierDteJson(),
            ])
            ->assertOk()
            ->assertJsonPath('data.supplier.matched.name', 'Proveedor Uno')
            ->assertJsonPath('data.document.document_type', 'dte_ccf')
            ->assertJsonPath('data.document.fovial_per_unit', 0.2)
            ->assertJsonPath('data.document.cotrans_per_unit', 0.1)
            ->assertJsonPath('data.lines.0.matched_catalog_item.id', $item->id);
    }

    public function test_purchase_supports_consumables_without_inventory_and_fuel_charges(): void
    {
        [$owner, $tenant] = $this->userWithTenantRole('owner');
        $item = $this->inventoryItem($tenant, 'GAS-002');

        $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/purchases", [
                'document_type' => 'dte_ccf',
                'document_number' => 'ABC-123',
                'payment_condition' => 'cash',
                'document_total' => 116,
                'purchase_date' => '2026-06-30',
                'is_consumable' => true,
                'apply_fuel_charges' => true,
                'fovial_per_unit' => 0.2,
                'cotrans_per_unit' => 0.1,
                'lines' => [
                    ['catalog_item_id' => $item->id, 'quantity' => 10, 'unit_cost' => 10],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.total', '116.00')
            ->assertJsonPath('data.lines.0.no_inventory', true);

        $item->refresh();
        $this->assertSame(0.0, (float) $item->stock_quantity);
        $this->assertDatabaseMissing('inventory_lots', ['catalog_item_id' => $item->id]);
        $this->assertDatabaseMissing('inventory_movements', ['catalog_item_id' => $item->id, 'reason' => 'purchase']);
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

    private function lot(Tenant $tenant, CatalogItem $item, string $code, string $date, float $quantity, float $cost): InventoryLot
    {
        return InventoryLot::query()->create([
            'tenant_id' => $tenant->id,
            'catalog_item_id' => $item->id,
            'lot_code' => $code,
            'received_date' => $date,
            'unit_cost' => $cost,
            'initial_quantity' => $quantity,
            'available_quantity' => $quantity,
            'status' => 'active',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function supplierDteJson(): array
    {
        return [
            'identificacion' => [
                'tipoDte' => '03',
                'codigoGeneracion' => 'ABC-123',
                'numeroControl' => 'DTE-03-M001P001-000000000000123',
                'fecEmi' => '2026-06-30',
            ],
            'emisor' => [
                'nit' => '0614-010101-101-1',
                'nrc' => '123456',
                'nombre' => 'Proveedor Uno',
            ],
            'resumen' => [
                'condicionOperacion' => 1,
                'totalPagar' => 113.3,
                'tributos' => [
                    ['codigo' => 'C8', 'descripcion' => 'FOVIAL', 'valor' => 2],
                    ['codigo' => '59', 'descripcion' => 'COTRANS', 'valor' => 1],
                ],
            ],
            'cuerpoDocumento' => [
                [
                    'numItem' => 1,
                    'descripcion' => 'Gasolina regular',
                    'cantidad' => 10,
                    'uniMedida' => '22',
                    'ventaGravada' => 100,
                ],
            ],
        ];
    }
}

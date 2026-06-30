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

    public function test_dte_json_import_strips_supplier_code_prefix_from_description(): void
    {
        [$owner, $tenant] = $this->userWithTenantRole('owner');
        $item = $this->inventoryItem($tenant, 'CAN-001');
        $item->forceFill(['name' => 'Canon Pixma MegaTank G3170 AIO WIFI 11/6 IPM'])->save();
        $payload = $this->supplierDteJson();
        $payload['cuerpoDocumento'][0]['descripcion'] = '5805C004AB|Canon Pixma MegaTank G3170 AIO WIFI 11/6 IPM';

        $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/purchases/import-dte-json", [
                'payload' => $payload,
            ])
            ->assertOk()
            ->assertJsonPath('data.lines.0.description', 'Canon Pixma MegaTank G3170 AIO WIFI 11/6 IPM')
            ->assertJsonPath('data.lines.0.supplier_code', '5805C004AB')
            ->assertJsonPath('data.lines.0.matched_catalog_item.id', $item->id);
    }

    public function test_dte_json_import_does_not_match_similar_product_with_different_model(): void
    {
        [$owner, $tenant] = $this->userWithTenantRole('owner');
        $item = $this->inventoryItem($tenant, 'CAN-G3170');
        $item->forceFill(['name' => 'Canon Pixma MegaTank G3170 AIO WIFI 11/6 IPM'])->save();
        $payload = $this->supplierDteJson();
        $payload['cuerpoDocumento'][0]['descripcion'] = 'Canon Pixma MegaTank G2170 AIO WIFI 11/6 IPM';

        $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/purchases/import-dte-json", [
                'payload' => $payload,
            ])
            ->assertOk()
            ->assertJsonPath('data.lines.0.description', 'Canon Pixma MegaTank G2170 AIO WIFI 11/6 IPM')
            ->assertJsonPath('data.lines.0.matched_catalog_item', null);
    }

    public function test_dte_json_import_preserves_supplier_code_without_matching_it_as_internal_sku(): void
    {
        [$owner, $tenant] = $this->userWithTenantRole('owner');
        $item = $this->inventoryItem($tenant, '5805C004AB');
        $item->forceFill(['name' => 'Canon Pixma MegaTank G3170 AIO WIFI 11/6 IPM'])->save();
        $payload = $this->supplierDteJson();
        $payload['cuerpoDocumento'][0]['codigo'] = '5805C004AB';
        $payload['cuerpoDocumento'][0]['descripcion'] = 'Canon Pixma MegaTank G3170 Multifuncional';

        $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/purchases/import-dte-json", [
                'payload' => $payload,
            ])
            ->assertOk()
            ->assertJsonPath('data.lines.0.supplier_code', '5805C004AB')
            ->assertJsonPath('data.lines.0.matched_catalog_item', null);
    }

    public function test_dte_json_import_detects_tax_perceived_from_tributes(): void
    {
        [$owner, $tenant] = $this->userWithTenantRole('owner');
        $payload = $this->supplierDteJson();
        $payload['resumen']['totalPagar'] = 114.3;
        $payload['resumen']['tributos'][] = ['codigo' => 'C5', 'descripcion' => 'IVA Percibido', 'valor' => 1];

        $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/purchases/import-dte-json", [
                'payload' => $payload,
            ])
            ->assertOk()
            ->assertJsonPath('data.document.apply_tax_perceived', true)
            ->assertJsonPath('data.document.tax_perceived_mode', 'dte')
            ->assertJsonPath('data.document.tax_perceived_amount', 1);
    }

    public function test_dte_json_import_uses_iva_perci1_without_confusing_regular_iva(): void
    {
        [$owner, $tenant] = $this->userWithTenantRole('owner');
        $payload = $this->supplierDteJson();
        $payload['resumen']['totalPagar'] = 114.3;
        $payload['resumen']['ivaPerci1'] = 1;
        $payload['resumen']['tributos'][] = ['codigo' => '20', 'descripcion' => 'Impuesto al Valor Agregado 13%', 'valor' => 13];

        $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/purchases/import-dte-json", [
                'payload' => $payload,
            ])
            ->assertOk()
            ->assertJsonPath('data.document.apply_tax_perceived', true)
            ->assertJsonPath('data.document.tax_perceived_amount', 1);
    }

    public function test_dte_json_import_does_not_treat_regular_iva_tribute_as_perceived(): void
    {
        [$owner, $tenant] = $this->userWithTenantRole('owner');
        $payload = $this->supplierDteJson();
        $payload['resumen']['tributos'][] = ['codigo' => '20', 'descripcion' => 'Impuesto al Valor Agregado 13%', 'valor' => 13];

        $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/purchases/import-dte-json", [
                'payload' => $payload,
            ])
            ->assertOk()
            ->assertJsonPath('data.document.apply_tax_perceived', false)
            ->assertJsonPath('data.document.tax_perceived_amount', 0);
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

    public function test_purchase_uses_detected_tax_perceived_amount(): void
    {
        [$owner, $tenant] = $this->userWithTenantRole('owner');
        $item = $this->inventoryItem($tenant, 'IVA-001');

        $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/purchases", [
                'document_type' => 'dte_ccf',
                'document_mode' => 'dte',
                'document_number' => 'IVA-PERC-1',
                'payment_condition' => 'cash',
                'document_total' => 114,
                'purchase_date' => '2026-06-30',
                'apply_tax_perceived' => true,
                'tax_perceived_mode' => 'dte',
                'tax_perceived_amount' => 1,
                'lines' => [
                    ['catalog_item_id' => $item->id, 'quantity' => 1, 'unit_cost' => 100],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.tax_perceived', '1.00')
            ->assertJsonPath('data.total', '114.00');
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

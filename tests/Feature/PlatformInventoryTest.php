<?php

namespace Tests\Feature;

use App\Models\CatalogItem;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryPurchase;
use App\Models\InventorySale;
use App\Models\InventorySupplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Inventory\InventoryPurchaseAnnexExportService;
use App\Services\Inventory\LegacyInventoryImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_can_consult_inventory_but_cannot_change_stock_or_sales(): void
    {
        [$owner, $tenant] = $this->userWithTenantRole('owner');
        [$viewer] = $this->userWithTenantRole('viewer', $tenant);
        $item = $this->inventoryItem($tenant, 'VIEW-001');
        $this->lot($tenant, $item, 'VIEW-LOT', '2026-07-20', 2, 10);
        $item->forceFill(['stock_quantity' => 2, 'reference_cost' => 10, 'cost_source' => 'real'])->save();

        $reservation = $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/reservations", [
                'idempotency_key' => 'viewer-permission-check',
                'lines' => [['catalog_item_id' => $item->id, 'quantity' => 1]],
            ])
            ->assertCreated()
            ->json('data');

        $base = "/api/v1/platform/tenants/{$tenant->id}/inventory";
        $this->actingAs($viewer)->getJson($base.'/reports/summary')->assertOk();

        $this->postJson($base.'/reservations', [])->assertForbidden();
        $this->postJson($base."/reservations/{$reservation['id']}/confirm", [])->assertForbidden();
        $this->postJson($base."/reservations/{$reservation['id']}/release", [])->assertForbidden();
        $this->postJson($base."/reservations/{$reservation['id']}/reverse", [])->assertForbidden();
        $this->postJson($base.'/sales', [])->assertForbidden();
        $this->postJson($base.'/sales/supersede-by-source', [])->assertForbidden();
        $this->postJson($base.'/sales/reverse-by-source', [])->assertForbidden();

        $this->assertDatabaseHas('inventory_reservations', [
            'id' => $reservation['id'],
            'status' => 'reserved',
        ]);
    }

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

    public function test_inventory_reservation_consumes_only_selected_branch(): void
    {
        [$owner, $tenant] = $this->userWithTenantRole('owner');
        $item = $this->inventoryItem($tenant, 'BR-001');

        $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/purchases", [
                'core_sucursal_id' => 1,
                'core_sucursal_code' => 'M001',
                'core_sucursal_name' => 'Casa matriz',
                'purchase_date' => '2026-07-01',
                'lines' => [
                    ['catalog_item_id' => $item->id, 'quantity' => 2, 'unit_cost' => 10],
                ],
            ])
            ->assertCreated();

        $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/purchases", [
                'core_sucursal_id' => 2,
                'core_sucursal_code' => 'S002',
                'core_sucursal_name' => 'Sucursal dos',
                'purchase_date' => '2026-07-01',
                'lines' => [
                    ['catalog_item_id' => $item->id, 'quantity' => 3, 'unit_cost' => 12],
                ],
            ])
            ->assertCreated();

        $reserve = $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/reservations", [
                'idempotency_key' => 'branch-issue-1',
                'core_sucursal_id' => 1,
                'core_sucursal_code' => 'M001',
                'core_sucursal_name' => 'Casa matriz',
                'lines' => [
                    ['catalog_item_id' => $item->id, 'quantity' => 2],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.core_sucursal_id', 1)
            ->json('data');

        $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/reservations/{$reserve['id']}/confirm", [
                'source_type' => 'dte',
                'source_id' => 'BR-1',
                'source_number' => 'DTE-BR-1',
            ])
            ->assertOk();

        $this->assertDatabaseHas('inventory_lots', [
            'tenant_id' => $tenant->id,
            'catalog_item_id' => $item->id,
            'core_sucursal_id' => 1,
            'available_quantity' => 0,
        ]);
        $this->assertDatabaseHas('inventory_lots', [
            'tenant_id' => $tenant->id,
            'catalog_item_id' => $item->id,
            'core_sucursal_id' => 2,
            'available_quantity' => 3,
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'tenant_id' => $tenant->id,
            'catalog_item_id' => $item->id,
            'core_sucursal_id' => 1,
            'movement_type' => 'exit',
            'reason' => 'sale',
        ]);
    }

    public function test_branch_reservation_reports_stock_in_other_branch(): void
    {
        [$owner, $tenant] = $this->userWithTenantRole('owner');
        $item = $this->inventoryItem($tenant, 'BR-002');
        $this->lot($tenant, $item, 'L-S002', '2026-07-01', 3, 12, [
            'core_sucursal_id' => 2,
            'core_sucursal_code' => 'S002',
            'core_sucursal_name' => 'Sucursal dos',
        ]);
        $item->forceFill(['stock_quantity' => 3, 'reference_cost' => 12, 'cost_source' => 'real'])->save();

        $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/reservations", [
                'idempotency_key' => 'branch-issue-2',
                'core_sucursal_id' => 1,
                'core_sucursal_code' => 'M001',
                'core_sucursal_name' => 'Casa matriz',
                'lines' => [
                    ['catalog_item_id' => $item->id, 'quantity' => 1],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Stock insuficiente para Item BR-002 en M001. Disponible: 0; requerido: 1. En otras sucursales: S002: 3.']);
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
            ->assertJsonPath('data.document.subtotal', 100)
            ->assertJsonPath('data.document.tax_amount', 13)
            ->assertJsonPath('data.document.fovial_per_unit', 0.2)
            ->assertJsonPath('data.document.cotrans_per_unit', 0.1)
            ->assertJsonPath('data.lines.0.subtotal', 100)
            ->assertJsonPath('data.lines.0.matched_catalog_item.id', $item->id);
    }

    public function test_dte_json_import_detects_fuel_charges_when_unit_is_99_and_description_is_diesel(): void
    {
        [$owner, $tenant] = $this->userWithTenantRole('owner');
        $item = $this->inventoryItem($tenant, 'DIE-001');
        $item->forceFill(['name' => 'DIESEL', 'unit_code' => '99'])->save();

        $payload = [
            'identificacion' => [
                'tipoDte' => '03',
                'codigoGeneracion' => 'FUEL-PUMA-1',
                'numeroControl' => 'DTE-03-M001P003-000000000023544',
                'fecEmi' => '2026-08-28',
            ],
            'emisor' => ['nit' => '13152512681011', 'nrc' => '901679', 'nombre' => 'ESTACION PUMA'],
            'cuerpoDocumento' => [[
                'numItem' => 1,
                'descripcion' => 'DIESEL',
                'cantidad' => 4.3764,
                'uniMedida' => 99,
                'precioUni' => 3.7788,
                'ventaGravada' => 16.5375,
                'tributos' => ['20', 'D1', 'C8'],
            ]],
            'resumen' => [
                'condicionOperacion' => 1,
                'totalNoSuj' => 0,
                'totalExenta' => 0,
                'totalGravada' => 16.54,
                'subTotalVentas' => 16.54,
                'tributos' => [
                    ['codigo' => '20', 'descripcion' => 'Impuesto al Valor Agregado 13%', 'valor' => 2.15],
                    ['codigo' => 'D1', 'descripcion' => 'FOVIAL ($0.20 Ctvs. por galón)', 'valor' => 0.88],
                    ['codigo' => 'C8', 'descripcion' => 'COTRANS ($0.10 Ctvs. por galón)', 'valor' => 0.44],
                ],
                'montoTotalOperacion' => 20,
                'totalPagar' => 20,
            ],
        ];

        $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/purchases/import-dte-json", [
                'payload' => $payload,
            ])
            ->assertOk()
            ->assertJsonPath('data.document.apply_fuel_charges', true)
            ->assertJsonPath('data.document.subtotal', 16.54)
            ->assertJsonPath('data.document.tax_amount', 2.15)
            ->assertJsonPath('data.document.fovial_per_unit', 0.2011)
            ->assertJsonPath('data.document.cotrans_per_unit', 0.1005)
            ->assertJsonPath('data.lines.0.is_fuel', true)
            ->assertJsonPath('data.lines.0.no_inventory', true)
            ->assertJsonPath('data.lines.0.matched_catalog_item.id', $item->id);
    }

    public function test_dte_json_import_rejects_an_existing_document_during_preview(): void
    {
        [$owner, $tenant] = $this->userWithTenantRole('owner');
        $purchase = InventoryPurchase::query()->create([
            'tenant_id' => $tenant->id,
            'document_type' => 'dte_ccf',
            'document_mode' => 'dte',
            'document_number' => 'ABC-123',
            'purchase_date' => '2026-06-30',
        ]);

        $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/purchases/import-dte-json", [
                'payload' => $this->supplierDteJson(),
            ])
            ->assertConflict()
            ->assertJsonPath('message', 'Este DTE ya fue importado. Selecciona otro archivo.')
            ->assertJsonPath('duplicate.purchase_id', $purchase->id)
            ->assertJsonPath('duplicate.document_number', 'ABC-123')
            ->assertJsonPath('duplicate.purchase_date', '2026-06-30');
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

    public function test_dte_json_import_uses_regular_iva_tribute_when_total_iva_is_missing(): void
    {
        [$owner, $tenant] = $this->userWithTenantRole('owner');
        $payload = $this->supplierDteJson();
        $payload['resumen']['totalIva'] = 0;
        $payload['resumen']['totalPagar'] = 10.99;
        $payload['resumen']['totalGravada'] = 9.73;
        $payload['resumen']['tributos'] = [
            ['codigo' => '20', 'descripcion' => 'Impuesto al Valor Agregado 13%', 'valor' => 1.26],
        ];
        $payload['cuerpoDocumento'][0]['ventaGravada'] = 9.73;
        $payload['cuerpoDocumento'][0]['cantidad'] = 1;

        $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/purchases/import-dte-json", [
                'payload' => $payload,
            ])
            ->assertOk()
            ->assertJsonPath('data.document.subtotal', 9.73)
            ->assertJsonPath('data.document.tax_amount', 1.26)
            ->assertJsonPath('data.document.document_total', 10.99)
            ->assertJsonPath('data.document.apply_tax_perceived', false)
            ->assertJsonPath('data.import_metadata.tributes.0.codigo', '20');
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

    public function test_dte_purchase_respects_document_tax_rounding(): void
    {
        [$owner, $tenant] = $this->userWithTenantRole('owner');
        $item = $this->inventoryItem($tenant, 'ROUND-001');

        $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/purchases", [
                'document_type' => 'dte_ccf',
                'document_mode' => 'dte',
                'document_number' => 'ROUND-1',
                'payment_condition' => 'cash',
                'tax_amount' => 13.01,
                'document_total' => 113.01,
                'purchase_date' => '2026-06-30',
                'lines' => [
                    ['catalog_item_id' => $item->id, 'quantity' => 3, 'unit_cost' => 33.3333, 'subtotal' => 100],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.subtotal', '100.00')
            ->assertJsonPath('data.tax_amount', '13.01')
            ->assertJsonPath('data.total', '113.01');
    }

    public function test_record_sale_tracks_inventory_catalog_and_free_lines_for_reports(): void
    {
        [$owner, $tenant] = $this->userWithTenantRole('owner');
        $inventory = $this->inventoryItem($tenant, 'INV-REP');
        $catalog = CatalogItem::query()->create([
            'tenant_id' => $tenant->id,
            'sku' => 'CAT-REP',
            'name' => 'Servicio catalogado',
            'item_type' => 'service',
            'controls_inventory' => false,
            'base_price' => 20,
            'stock_quantity' => 0,
            'status' => 'active',
        ]);

        $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/sales", [
                'source_type' => 'dte',
                'source_id' => 'DTE-REP-1',
                'source_number' => 'DTE-01-REP',
                'sale_date' => '2026-07-01',
                'lines' => [
                    ['catalog_item_id' => $inventory->id, 'line_origin' => 'inventory', 'description' => $inventory->name, 'quantity' => 2, 'unit_price' => 25, 'net_total' => 50, 'reference_unit_cost' => 10],
                    ['catalog_item_id' => $catalog->id, 'line_origin' => 'catalog', 'description' => $catalog->name, 'quantity' => 1, 'unit_price' => 20, 'net_total' => 20],
                    ['line_origin' => 'free', 'description' => 'Descripcion libre', 'quantity' => 3, 'unit_price' => 5, 'net_total' => 15],
                ],
            ])
            ->assertCreated();

        $this->actingAs($owner)
            ->getJson("/api/v1/platform/tenants/{$tenant->id}/inventory/reports/sales?from=2026-07-01&to=2026-07-01")
            ->assertOk()
            ->assertJsonFragment(['name' => $inventory->name, 'quantity' => '2.000', 'sales_total' => 50])
            ->assertJsonFragment(['name' => $catalog->name, 'quantity' => '1.000', 'sales_total' => 20])
            ->assertJsonFragment(['name' => 'Descripcion libre', 'quantity' => '3.000', 'sales_total' => 15]);
    }

    public function test_invoicing_existing_workshop_sale_updates_financial_breakdown_without_duplication(): void
    {
        [$owner, $tenant] = $this->userWithTenantRole('owner');
        $url = "/api/v1/platform/tenants/{$tenant->id}/inventory/sales";
        $base = [
            'source_type' => 'workshop_order',
            'source_id' => '77',
            'source_number' => 'T-000077',
            'lines' => [['description' => 'Reparación', 'quantity' => 1, 'unit_price' => 113, 'net_total' => 113, 'total_amount' => 113]],
        ];
        $this->actingAs($owner)->postJson($url, $base)->assertCreated();

        $this->postJson($url, [
            ...$base,
            'fiscal_document_type' => '03',
            'net_amount' => 100,
            'tax_amount' => 13,
            'total_amount' => 113,
            'lines' => [['description' => 'Reparación', 'quantity' => 1, 'unit_price' => 100, 'net_total' => 100, 'tax_amount' => 13, 'total_amount' => 113]],
        ])->assertOk()
            ->assertJsonPath('data.fiscal_document_type', '03')
            ->assertJsonPath('data.net_amount', '100.00')
            ->assertJsonPath('data.tax_amount', '13.00')
            ->assertJsonPath('data.lines.0.net_total', '100.00')
            ->assertJsonPath('data.lines.0.tax_amount', '13.00');

        $this->assertDatabaseCount('inventory_sales', 1);
        $this->assertDatabaseCount('inventory_sale_lines', 1);
    }

    public function test_reverse_by_source_marks_sale_and_restores_confirmed_reservation(): void
    {
        [$owner, $tenant] = $this->userWithTenantRole('owner');
        $item = $this->inventoryItem($tenant, 'REV-SRC');
        $lot = $this->lot($tenant, $item, 'L-REV-SRC', '2026-07-01', 1, 9);
        $item->forceFill(['stock_quantity' => 1, 'reference_cost' => 9, 'cost_source' => 'real'])->save();

        $reserve = $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/reservations", [
                'idempotency_key' => 'reverse-by-source',
                'lines' => [
                    ['catalog_item_id' => $item->id, 'quantity' => 1],
                ],
            ])
            ->assertCreated()
            ->json('data');

        $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/reservations/{$reserve['id']}/confirm", [
                'source_type' => 'dte',
                'source_id' => '900',
                'source_number' => 'DTE-900',
            ])
            ->assertOk();

        $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/sales", [
                'source_type' => 'dte',
                'source_id' => '900',
                'source_number' => 'DTE-900',
                'lines' => [
                    ['catalog_item_id' => $item->id, 'line_origin' => 'inventory', 'quantity' => 1, 'net_total' => 25],
                ],
            ])
            ->assertCreated();

        $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/sales/reverse-by-source", [
                'source_type' => 'dte',
                'source_id' => '900',
                'event_id' => 'EV-900',
                'event_number' => 'ANU-900',
            ])
            ->assertOk()
            ->assertJsonPath('data.sale.status', 'reversed')
            ->assertJsonPath('data.reservation.status', 'reversed');

        $item->refresh();
        $lot->refresh();
        $this->assertSame(1.0, (float) $item->stock_quantity);
        $this->assertSame(1.0, (float) $lot->available_quantity);
        $this->assertDatabaseHas('inventory_movements', [
            'tenant_id' => $tenant->id,
            'catalog_item_id' => $item->id,
            'reason' => 'reversal',
            'reference_type' => 'mh_invalidation',
            'reference_id' => 'EV-900',
        ]);
    }

    public function test_replacement_inherits_confirmed_inventory_without_consuming_another_unit(): void
    {
        [$owner, $tenant] = $this->userWithTenantRole('owner');
        $item = $this->inventoryItem($tenant, 'SUB-INV');
        $lot = $this->lot($tenant, $item, 'L-SUB-INV', '2026-07-01', 1, 18, [
            'core_sucursal_id' => 1,
            'core_sucursal_code' => 'M001',
            'core_sucursal_name' => 'Casa matriz',
        ]);
        $item->forceFill(['stock_quantity' => 1, 'reference_cost' => 18, 'cost_source' => 'real'])->save();

        $reservation = $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/reservations", [
                'idempotency_key' => 'substitution-original',
                'core_sucursal_id' => 1,
                'lines' => [['catalog_item_id' => $item->id, 'quantity' => 1]],
            ])->assertCreated()->json('data');

        $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/reservations/{$reservation['id']}/confirm", [
                'source_type' => 'dte',
                'source_id' => '1001',
                'source_number' => 'DTE-01-M001P001-000000000001001',
            ])->assertOk();

        $original = $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/sales", [
                'core_sucursal_id' => 1,
                'source_type' => 'dte',
                'source_id' => '1001',
                'lines' => [[
                    'catalog_item_id' => $item->id,
                    'line_origin' => 'inventory',
                    'quantity' => 1,
                    'unit_price' => 25,
                    'net_total' => 25,
                ]],
            ])->assertCreated()->json('data');

        $fulfillment = $this->actingAs($owner)
            ->getJson("/api/v1/platform/tenants/{$tenant->id}/inventory/sales/fulfillment-by-source?source_type=dte&source_id=1001")
            ->assertOk()
            ->assertJsonPath('data.sale.id', $original['id'])
            ->assertJsonPath('data.reservation.status', 'confirmed')
            ->json('data');

        $sourceLineId = $fulfillment['sale']['lines'][0]['id'];
        $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/sales", [
                'core_sucursal_id' => 1,
                'source_type' => 'dte',
                'source_id' => '1002-invalid',
                'replacement_of_source_type' => 'dte',
                'replacement_of_source_id' => '1001',
                'lines' => [[
                    'catalog_item_id' => $item->id,
                    'line_origin' => 'inventory',
                    'inherited_from_line_id' => $sourceLineId,
                    'inherited_quantity' => 2,
                    'quantity' => 2,
                    'net_total' => 50,
                ]],
            ])->assertUnprocessable()
            ->assertJsonPath('message', 'La cantidad heredada supera la salida registrada en el DTE original.');

        $replacement = $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/sales", [
                'core_sucursal_id' => 1,
                'source_type' => 'dte',
                'source_id' => '1002',
                'replacement_of_source_type' => 'dte',
                'replacement_of_source_id' => '1001',
                'lines' => [[
                    'catalog_item_id' => $item->id,
                    'line_origin' => 'inventory',
                    'inherited_from_line_id' => $sourceLineId,
                    'inherited_quantity' => 1,
                    'quantity' => 1,
                    'unit_price' => 25,
                    'net_total' => 25,
                ]],
            ])->assertCreated()
            ->assertJsonPath('data.status', 'pending_replacement')
            ->assertJsonPath('data.lines.0.inherited_quantity', '1.000')
            ->json('data');

        $item->refresh();
        $lot->refresh();
        $this->assertSame(0.0, (float) $item->stock_quantity);
        $this->assertSame(0.0, (float) $lot->available_quantity);
        $this->assertSame(1, InventoryMovement::query()->where('catalog_item_id', $item->id)->where('movement_type', 'exit')->count());

        $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/sales/supersede-by-source", [
                'source_type' => 'dte',
                'original_source_id' => '1001',
                'replacement_source_id' => '1002',
                'event_id' => '51',
                'event_number' => 'EVENT-SUB-51',
            ])->assertOk()
            ->assertJsonPath('data.original.status', 'superseded')
            ->assertJsonPath('data.replacement.status', 'active');

        $this->assertDatabaseHas('inventory_sales', ['id' => $original['id'], 'status' => 'superseded']);
        $this->assertDatabaseHas('inventory_sales', ['id' => $replacement['id'], 'status' => 'active', 'replacement_of_sale_id' => $original['id']]);
    }

    public function test_catalog_replacement_is_financially_pending_without_inventory_fulfillment(): void
    {
        [$owner, $tenant] = $this->userWithTenantRole('owner');
        $catalog = CatalogItem::query()->create([
            'tenant_id' => $tenant->id,
            'sku' => 'SUB-CAT',
            'name' => 'Servicio de catalogo',
            'item_type' => 'service',
            'controls_inventory' => false,
            'base_price' => 40,
            'stock_quantity' => 0,
            'status' => 'active',
        ]);

        $original = $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/sales", [
                'source_type' => 'dte',
                'source_id' => '2001',
                'lines' => [['catalog_item_id' => $catalog->id, 'line_origin' => 'catalog', 'quantity' => 1, 'net_total' => 40]],
            ])->assertCreated()->json('data');

        $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/sales", [
                'source_type' => 'dte',
                'source_id' => '2002',
                'replacement_of_source_type' => 'dte',
                'replacement_of_source_id' => '2001',
                'lines' => [['catalog_item_id' => $catalog->id, 'line_origin' => 'catalog', 'quantity' => 1, 'net_total' => 40]],
            ])->assertCreated()
            ->assertJsonPath('data.status', 'pending_replacement')
            ->assertJsonPath('data.replacement_of_sale_id', $original['id']);

        $this->assertSame(0, InventoryMovement::query()->where('catalog_item_id', $catalog->id)->count());
        $this->assertSame(1, InventorySale::query()->where('tenant_id', $tenant->id)->where('status', 'active')->count());
    }

    public function test_physical_count_adjusts_branch_stock(): void
    {
        [$owner, $tenant] = $this->userWithTenantRole('owner');
        $item = $this->inventoryItem($tenant, 'COUNT-001');
        $this->lot($tenant, $item, 'L-COUNT', '2026-07-01', 5, 4, [
            'core_sucursal_id' => 1,
            'core_sucursal_code' => 'M001',
            'core_sucursal_name' => 'Casa matriz',
        ]);
        $item->forceFill(['stock_quantity' => 5, 'reference_cost' => 4, 'cost_source' => 'real'])->save();

        $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/counts", [
                'core_sucursal_id' => 1,
                'core_sucursal_code' => 'M001',
                'core_sucursal_name' => 'Casa matriz',
                'count_date' => '2026-07-01',
                'lines' => [
                    ['catalog_item_id' => $item->id, 'counted_quantity' => 3],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.lines.0.difference_quantity', '-2.000');

        $item->refresh();
        $this->assertSame(3.0, (float) $item->stock_quantity);
        $this->assertDatabaseHas('inventory_movements', [
            'tenant_id' => $tenant->id,
            'catalog_item_id' => $item->id,
            'movement_type' => 'exit',
            'reason' => 'physical_count',
        ]);
    }

    public function test_transfer_moves_stock_between_branches_without_changing_total(): void
    {
        [$owner, $tenant] = $this->userWithTenantRole('owner');
        $item = $this->inventoryItem($tenant, 'TRF-001');
        $this->lot($tenant, $item, 'L-TRF', '2026-07-01', 5, 7, [
            'core_sucursal_id' => 1,
            'core_sucursal_code' => 'M001',
            'core_sucursal_name' => 'Casa matriz',
        ]);
        $item->forceFill(['stock_quantity' => 5, 'reference_cost' => 7, 'cost_source' => 'real'])->save();

        $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/transfers", [
                'from_core_sucursal_id' => 1,
                'from_core_sucursal_code' => 'M001',
                'from_core_sucursal_name' => 'Casa matriz',
                'to_core_sucursal_id' => 2,
                'to_core_sucursal_code' => 'S002',
                'to_core_sucursal_name' => 'Sucursal dos',
                'lines' => [
                    ['catalog_item_id' => $item->id, 'quantity' => 2],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.lines.0.quantity', '2.000');

        $item->refresh();
        $this->assertSame(5.0, (float) $item->stock_quantity);
        $this->assertSame(3.0, (float) InventoryLot::query()->where('catalog_item_id', $item->id)->where('core_sucursal_id', 1)->sum('available_quantity'));
        $this->assertSame(2.0, (float) InventoryLot::query()->where('catalog_item_id', $item->id)->where('core_sucursal_id', 2)->sum('available_quantity'));
        $this->assertDatabaseHas('inventory_movements', ['catalog_item_id' => $item->id, 'reason' => 'transfer_out']);
        $this->assertDatabaseHas('inventory_movements', ['catalog_item_id' => $item->id, 'reason' => 'transfer_in']);
    }

    public function test_stock_alerts_report_items_below_minimum(): void
    {
        [$owner, $tenant] = $this->userWithTenantRole('owner');
        $item = $this->inventoryItem($tenant, 'MIN-001');
        $item->forceFill(['min_stock_quantity' => 5])->save();
        $this->lot($tenant, $item, 'L-MIN', '2026-07-01', 2, 3);
        $item->forceFill(['stock_quantity' => 2])->save();

        $this->actingAs($owner)
            ->getJson("/api/v1/platform/tenants/{$tenant->id}/inventory/reports/stock-alerts")
            ->assertOk()
            ->assertJsonFragment([
                'id' => $item->id,
                'sku' => 'MIN-001',
                'below_minimum' => true,
            ]);
    }

    public function test_inventory_summary_aggregates_every_lot_and_uses_exclusive_stock_segments(): void
    {
        [$owner, $tenant] = $this->userWithTenantRole('owner');
        $healthy = $this->inventoryItem($tenant, 'SUM-HEALTHY');
        $low = $this->inventoryItem($tenant, 'SUM-LOW');
        $empty = $this->inventoryItem($tenant, 'SUM-EMPTY');
        $healthy->forceFill(['min_stock_quantity' => 5, 'stock_quantity' => 101])->save();
        $low->forceFill(['min_stock_quantity' => 5, 'stock_quantity' => 2])->save();
        $empty->forceFill(['min_stock_quantity' => 5, 'stock_quantity' => 0])->save();

        foreach (range(1, 101) as $number) {
            $this->lot($tenant, $healthy, 'SUM-H-'.$number, '2026-07-01', 1, 3, ['core_sucursal_id' => 1]);
        }
        $this->lot($tenant, $low, 'SUM-L-1', '2026-07-03', 2, 5, ['core_sucursal_id' => 1]);
        $this->lot($tenant, $healthy, 'SUM-H-OTHER', '2026-07-04', 7, 9, ['core_sucursal_id' => 2]);

        $this->actingAs($owner)
            ->getJson("/api/v1/platform/tenants/{$tenant->id}/inventory/reports/summary?core_sucursal_id=1")
            ->assertOk()
            ->assertJsonPath('data.products', 3)
            ->assertJsonPath('data.units', 103)
            ->assertJsonPath('data.inventory_value', 313)
            ->assertJsonPath('data.lots', 102)
            ->assertJsonPath('data.available_lots', 102)
            ->assertJsonPath('data.healthy', 1)
            ->assertJsonPath('data.below_minimum', 1)
            ->assertJsonPath('data.out_of_stock', 1)
            ->assertJsonCount(3, 'data.stock_by_item');
    }

    public function test_legacy_inventory_import_preserves_available_stock_and_non_inventory_lines(): void
    {
        $tenant = Tenant::query()->create(['slug' => 'legacy-import', 'name' => 'Legacy import']);
        $payload = [
            'legacy_tenant_id' => 1,
            'target_branch' => ['id' => 5, 'code' => 'M001', 'name' => 'Casa matriz'],
            'categories' => [['id' => 10, 'nombre' => 'Repuestos', 'tipo' => 'producto', 'activo' => true]],
            'suppliers' => [['id' => 20, 'razon_social' => 'Proveedor legado', 'ruc' => '06140101011011', 'activo' => true]],
            'items' => [[
                'id' => 30, 'categoria_item_id' => 10, 'proveedor_id' => 20, 'codigo' => 'LEG-30', 'nombre' => 'Repuesto legado',
                'unidad_medida' => '59', 'unidades_por_empaque' => 1, 'tipo' => 'producto', 'afecto_igv' => true,
                'precio_compra' => 10, 'precio_venta' => 20, 'precio_venta_incluye_iva' => true, 'controla_stock' => true,
                'stock_actual' => 2, 'activo' => true,
            ]],
            'purchases' => [[
                'id' => 40, 'numero_compra' => 1, 'proveedor_id' => 20, 'tipo_documento' => 'nota_envio',
                'modalidad_documento' => 'fisico', 'condicion_pago' => 'contado', 'fecha' => '2026-01-02',
                'subtotal' => 10, 'igv' => 1.30, 'total' => 11.30, 'estado' => 'registrada',
            ]],
            'purchase_lines' => [[
                'id' => 50, 'compra_id' => 40, 'item_id' => 30, 'cantidad' => 3, 'costo_unitario' => 10,
                'costo_base_unitario' => 10, 'iva_unitario' => 1.30, 'porcentaje_iva' => 0.13,
                'total_unitario' => 11.30, 'igv_monto' => 3.90, 'total_linea' => 33.90, 'no_inventario' => true,
            ]],
            'lots' => [[
                'id' => 60, 'item_id' => 30, 'proveedor_id' => 20, 'compra_id' => 40, 'compra_detalle_id' => 50,
                'codigo_lote' => 'LEGACY-60', 'fecha_compra' => '2026-01-02', 'costo_unitario' => 10,
                'cantidad_inicial' => 3, 'cantidad_disponible' => 2, 'activo' => true,
            ]],
            'movements' => [
                ['id' => 70, 'item_id' => 30, 'tipo_movimiento' => 'entrada', 'motivo' => 'compra', 'cantidad' => 3, 'fecha' => '2026-01-02 10:00:00'],
                ['id' => 71, 'item_id' => 30, 'tipo_movimiento' => 'salida', 'motivo' => 'venta', 'cantidad' => 1, 'fecha' => '2026-01-03 10:00:00'],
            ],
        ];

        $summary = app(LegacyInventoryImportService::class)->import($tenant, $payload);

        $this->assertSame(0.0, $summary['stock_difference']);
        $this->assertSame(0.0, $summary['movement_stock_difference']);
        $this->assertDatabaseHas('catalog_items', ['tenant_id' => $tenant->id, 'legacy_item_id' => 30, 'reference_cost' => 10, 'stock_quantity' => 2]);
        $this->assertDatabaseHas('catalog_items', ['tenant_id' => $tenant->id, 'legacy_item_id' => 30, 'min_stock_quantity' => 1]);
        $this->assertDatabaseHas('inventory_purchases', ['tenant_id' => $tenant->id, 'core_sucursal_id' => 5, 'document_mode' => 'physical', 'fiscal_annex_eligible' => false]);
        $this->assertDatabaseHas('inventory_purchase_lines', ['tenant_id' => $tenant->id, 'no_inventory' => true]);
        $this->assertDatabaseHas('inventory_lots', ['tenant_id' => $tenant->id, 'core_sucursal_id' => 5, 'initial_quantity' => 3, 'available_quantity' => 2]);
        $this->assertDatabaseHas('inventory_movements', ['tenant_id' => $tenant->id, 'core_sucursal_id' => 5, 'reason' => 'sale', 'balance_after' => 2]);
        $annex = app(InventoryPurchaseAnnexExportService::class)->build($tenant);
        $this->assertSame(0, $annex['meta']['counts']['compras']);

        app(LegacyInventoryImportService::class)->clear($tenant);
        $this->assertDatabaseMissing('catalog_items', ['tenant_id' => $tenant->id]);
        $this->assertDatabaseMissing('inventory_movements', ['tenant_id' => $tenant->id]);
    }

    public function test_purchase_annex_report_exposes_tax_purchase_base_data(): void
    {
        [$owner, $tenant] = $this->userWithTenantRole('owner');
        $supplier = InventorySupplier::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Proveedor Anexo',
            'tax_id' => '0614-010101-101-1',
            'nrc' => '123456-7',
            'status' => 'active',
        ]);
        $item = $this->inventoryItem($tenant, 'ANEXO-001');

        $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/purchases", [
                'inventory_supplier_id' => $supplier->id,
                'document_type' => 'dte_ccf',
                'document_mode' => 'dte',
                'document_number' => 'DTE-03-ANEXO',
                'payment_condition' => 'cash',
                'tax_amount' => 13,
                'document_total' => 113,
                'purchase_date' => '2026-07-01',
                'fiscal_profile' => 'administrative_expense',
                'fiscal_sector' => 2,
                'import_metadata' => ['codigoGeneracion' => 'ANEXO-UUID'],
                'lines' => [
                    ['catalog_item_id' => $item->id, 'quantity' => 1, 'unit_cost' => 100, 'subtotal' => 100],
                ],
            ])
            ->assertCreated();

        $this->actingAs($owner)
            ->getJson("/api/v1/platform/tenants/{$tenant->id}/inventory/reports/purchase-annex?from=2026-07-01&to=2026-07-01")
            ->assertOk()
            ->assertJsonFragment([
                'document_number' => 'DTE-03-ANEXO',
                'supplier_name' => 'Proveedor Anexo',
                'supplier_tax_id' => '0614-010101-101-1',
                'f07_sector' => 2,
            ])
            ->assertJsonPath('data.0.import_metadata.codigoGeneracion', 'ANEXO-UUID');
    }

    public function test_purchase_annex_official_exports_hacienda_rows(): void
    {
        [$owner, $tenant] = $this->userWithTenantRole('owner');
        $supplier = InventorySupplier::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Proveedor Anexo',
            'tax_id' => '0614-010101-101-1',
            'nrc' => '123456-7',
            'status' => 'active',
        ]);
        $item = $this->inventoryItem($tenant, 'ANEXO-002');

        $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/inventory/purchases", [
                'inventory_supplier_id' => $supplier->id,
                'document_type' => 'dte_ccf',
                'document_mode' => 'dte',
                'document_number' => 'DTE-03-ANEXO',
                'payment_condition' => 'cash',
                'tax_amount' => 13,
                'document_total' => 113,
                'purchase_date' => '2026-07-01',
                'fiscal_profile' => 'administrative_expense',
                'fiscal_sector' => 2,
                'import_metadata' => [
                    'tipo_dte' => '03',
                    'codigoGeneracion' => 'A1B2C3D4-E5F6-47A8-9B10-123456789ABC',
                    'numero_control' => 'DTE-03-M001P001-000000000000001',
                ],
                'lines' => [
                    ['catalog_item_id' => $item->id, 'quantity' => 1, 'unit_cost' => 100, 'subtotal' => 100],
                ],
            ])
            ->assertCreated();

        $this->actingAs($owner)
            ->getJson("/api/v1/platform/tenants/{$tenant->id}/inventory/reports/purchase-annex/official?from=2026-07-01&to=2026-07-31")
            ->assertOk()
            ->assertJsonPath('meta.counts.compras', 1)
            ->assertJsonPath('data.compras.official_rows.0.0', '01/07/2026')
            ->assertJsonPath('data.compras.official_rows.0.1', '4')
            ->assertJsonPath('data.compras.official_rows.0.2', '03')
            ->assertJsonPath('data.compras.official_rows.0.3', 'A1B2C3D4E5F647A89B10123456789ABC')
            ->assertJsonPath('data.compras.official_rows.0.4', '1234567')
            ->assertJsonPath('data.compras.official_rows.0.9', '100.00')
            ->assertJsonPath('data.compras.official_rows.0.13', '13.00')
            ->assertJsonPath('data.compras.official_rows.0.14', '113.00')
            ->assertJsonPath('data.compras.official_rows.0.16', '1')
            ->assertJsonPath('data.compras.official_rows.0.17', '2')
            ->assertJsonPath('data.compras.official_rows.0.18', '2')
            ->assertJsonPath('data.compras.official_rows.0.19', '2')
            ->assertJsonPath('data.compras.official_rows.0.20', '3');

        $this->actingAs($owner)
            ->get("/api/v1/platform/tenants/{$tenant->id}/inventory/reports/purchase-annex/csv?from=2026-07-01&to=2026-07-31")
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=Windows-1252');
    }

    public function test_purchase_annex_uses_supplier_dte_json_for_fuel_and_defaults_f07(): void
    {
        [$owner, $tenant] = $this->userWithTenantRole('owner');

        InventoryPurchase::query()->create([
            'tenant_id' => $tenant->id,
            'purchase_number' => 1,
            'document_type' => 'dte_ccf',
            'document_mode' => 'dte',
            'document_number' => 'FUEL-1',
            'payment_condition' => 'cash',
            'purchase_date' => '2026-07-02',
            'subtotal' => 100,
            'tax_amount' => 13,
            'tax_perceived' => 0,
            'fovial_per_unit' => 0,
            'cotrans_per_unit' => 0,
            'other_non_taxable_total' => 30,
            'total' => 143,
            'status' => 'registered',
            'import_metadata' => [
                'dte_json' => [
                    'identificacion' => [
                        'tipoDte' => '03',
                        'codigoGeneracion' => 'FUEL-UUID',
                        'numeroControl' => 'DTE-03-FUEL',
                        'fecEmi' => '2026-07-02',
                    ],
                    'emisor' => [
                        'nit' => '06140101011011',
                        'nrc' => '1234567',
                        'nombre' => 'Estacion de Servicio',
                    ],
                    'resumen' => [
                        'totalExenta' => 0,
                        'totalNoSuj' => 0,
                        'totalGravada' => 100,
                        'totalPagar' => 143,
                        'tributos' => [
                            ['codigo' => '20', 'descripcion' => 'IVA', 'valor' => 13],
                            ['codigo' => 'D1', 'descripcion' => 'FOVIAL', 'valor' => 20],
                            ['codigo' => 'C8', 'descripcion' => 'COTRANS', 'valor' => 10],
                        ],
                    ],
                    'cuerpoDocumento' => [
                        ['descripcion' => 'Combustible', 'cantidad' => 10, 'ventaGravada' => 100],
                    ],
                ],
            ],
        ]);

        $this->actingAs($owner)
            ->getJson("/api/v1/platform/tenants/{$tenant->id}/inventory/reports/purchase-annex/official?from=2026-07-01&to=2026-07-31")
            ->assertOk()
            ->assertJsonPath('data.compras.official_rows.0.6', '30.00')
            ->assertJsonPath('data.compras.official_rows.0.9', '100.00')
            ->assertJsonPath('data.compras.official_rows.0.13', '13.00')
            ->assertJsonPath('data.compras.official_rows.0.16', '1')
            ->assertJsonPath('data.compras.official_rows.0.17', '1')
            ->assertJsonPath('data.compras.official_rows.0.18', '2')
            ->assertJsonPath('data.compras.official_rows.0.19', '5')
            ->assertJsonPath('data.compras.preview.0.is_combustible', true);
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

    /**
     * @param  array<string, mixed>  $extra
     */
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
                'totalGravada' => 100,
                'totalIva' => 13,
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

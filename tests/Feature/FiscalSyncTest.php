<?php

namespace Tests\Feature;

use App\Models\CatalogItem;
use App\Models\FiscalSyncOperation;
use App\Models\InventoryLot;
use App\Models\PlatformApp;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FiscalSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepted_dte_completes_inventory_and_sale_idempotently(): void
    {
        [$user, $tenant] = $this->member();
        $item = $this->inventoryItem($tenant, 2);
        $operation = $this->actingAs($user)
            ->postJson($this->base($tenant).'/dte-issues', $this->issuePayload($item, 'sync-accepted'))
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.reservation.status', 'reserved')
            ->json('data');

        $fact = $this->acceptedDte(501);
        $this->postJson($this->base($tenant)."/operations/{$operation['id']}/complete", ['fact' => $fact])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.result.outcome', 'accepted')
            ->assertJsonPath('data.reservation.status', 'confirmed');

        $this->postJson($this->base($tenant)."/operations/{$operation['id']}/complete", ['fact' => $fact])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertDatabaseHas('inventory_sales', ['tenant_id' => $tenant->id, 'source_type' => 'dte', 'source_id' => '501', 'status' => 'active']);
        $this->assertDatabaseCount('inventory_sales', 1);
        $this->assertDatabaseCount('inventory_sale_lines', 1);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertSame(1.0, (float) $item->fresh()->stock_quantity);
    }

    public function test_rejected_dte_releases_reservation_without_recording_sale(): void
    {
        [$user, $tenant] = $this->member();
        $item = $this->inventoryItem($tenant, 1);
        $operation = $this->actingAs($user)
            ->postJson($this->base($tenant).'/dte-issues', $this->issuePayload($item, 'sync-rejected'))
            ->assertCreated()
            ->json('data');

        $this->postJson($this->base($tenant)."/operations/{$operation['id']}/complete", [
            'fact' => ['id' => 502, 'estado' => 'rejected', 'numeroControl' => 'DTE-01-REJECTED'],
        ])->assertOk()
            ->assertJsonPath('data.result.outcome', 'rejected')
            ->assertJsonPath('data.reservation.status', 'released');

        $this->assertDatabaseCount('inventory_sales', 0);
        $this->assertSame(1.0, (float) $item->fresh()->stock_quantity);
    }

    public function test_catalog_sale_is_recovered_by_server_without_browser_completion(): void
    {
        config([
            'services.dte_core.base_url' => 'https://core.test/api/v1',
            'services.dte_core.internal_token' => 'internal-secret',
            'services.dte_core.admin_email' => 'admin@stelfaro.test',
        ]);
        Http::fake([
            'https://core.test/api/v1/internal/auth/billing-session' => Http::response(['token' => 'backoffice-token']),
            'https://core.test/api/v1/dte/drafts*' => Http::response(['data' => [$this->acceptedDte(503)]]),
        ]);
        [$user, $tenant] = $this->member();
        $item = CatalogItem::query()->create([
            'tenant_id' => $tenant->id,
            'sku' => 'SERVICE-1',
            'name' => 'Servicio técnico',
            'item_type' => 'service',
            'controls_inventory' => false,
            'base_price' => 25,
            'stock_quantity' => 0,
            'status' => 'active',
        ]);
        $payload = $this->issuePayload($item, 'sync-recovery');
        $payload['reservation'] = null;
        $payload['sale']['lines'][0]['line_origin'] = 'catalog';

        $this->actingAs($user)->postJson($this->base($tenant).'/dte-issues', $payload)->assertCreated();

        $this->artisan('fiscal-sync:reconcile')->assertSuccessful();

        $this->assertDatabaseHas('fiscal_sync_operations', ['tenant_id' => $tenant->id, 'status' => 'completed', 'core_resource_id' => '503']);
        $this->assertDatabaseHas('inventory_sales', ['tenant_id' => $tenant->id, 'source_id' => '503', 'status' => 'active']);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://core.test/api/v1/dte/drafts')
            && str_contains($request->url(), 'idempotency_key=sync-recovery'));
    }

    public function test_stale_operation_without_fiscal_document_releases_its_reservation(): void
    {
        config([
            'services.dte_core.base_url' => 'https://core.test/api/v1',
            'services.dte_core.internal_token' => 'internal-secret',
            'services.dte_core.admin_email' => 'admin@stelfaro.test',
        ]);
        Http::fake([
            'https://core.test/api/v1/internal/auth/billing-session' => Http::response(['token' => 'backoffice-token']),
            'https://core.test/api/v1/dte/drafts*' => Http::response(['data' => []]),
        ]);
        [$user, $tenant] = $this->member();
        $item = $this->inventoryItem($tenant, 1);
        $operation = $this->actingAs($user)
            ->postJson($this->base($tenant).'/dte-issues', $this->issuePayload($item, 'sync-never-issued'))
            ->assertCreated()
            ->json('data');
        FiscalSyncOperation::query()->findOrFail($operation['id'])->forceFill([
            'created_at' => now()->subMinutes(31),
        ])->save();

        $this->artisan('fiscal-sync:reconcile')->assertSuccessful();

        $stored = FiscalSyncOperation::query()->findOrFail($operation['id']);
        $this->assertSame('completed', $stored->status);
        $this->assertSame('not_issued', $stored->result['outcome']);
        $this->assertDatabaseHas('inventory_reservations', [
            'tenant_id' => $tenant->id,
            'idempotency_key' => 'sync-never-issued',
            'status' => 'released',
        ]);
        $this->assertDatabaseCount('inventory_sales', 0);
        $this->assertSame(1.0, (float) $item->fresh()->stock_quantity);
    }

    public function test_payload_conflict_is_rejected_for_same_sync_key(): void
    {
        [$user, $tenant] = $this->member();
        $item = $this->inventoryItem($tenant, 2);
        $payload = $this->issuePayload($item, 'same-key');

        $this->actingAs($user)->postJson($this->base($tenant).'/dte-issues', $payload)->assertCreated();
        $payload['sale']['lines'][0]['quantity'] = 2;

        $this->postJson($this->base($tenant).'/dte-issues', $payload)
            ->assertConflict();
        $this->assertDatabaseCount('fiscal_sync_operations', 1);
        $this->assertDatabaseCount('inventory_reservations', 1);
    }

    public function test_accepted_type_two_invalidation_reverses_sale_and_inventory_once(): void
    {
        [$user, $tenant] = $this->member();
        $item = $this->inventoryItem($tenant, 1);
        $issue = $this->actingAs($user)
            ->postJson($this->base($tenant).'/dte-issues', $this->issuePayload($item, 'issue-before-invalidation'))
            ->assertCreated()
            ->json('data');
        $this->postJson($this->base($tenant)."/operations/{$issue['id']}/complete", [
            'fact' => $this->acceptedDte(504),
        ])->assertOk();

        $invalidation = $this->postJson($this->base($tenant).'/invalidations', [
            'idempotency_key' => 'invalidate-504',
            'invalidation_type' => 2,
            'original_source_id' => '504',
        ])->assertCreated()->json('data');

        $this->postJson($this->base($tenant)."/operations/{$invalidation['id']}/attach", [
            'core_resource_id' => '704',
        ])->assertOk();
        $fact = [
            'id' => 704,
            'estado' => 'accepted',
            'codigoGeneracion' => 'EVENT-704',
        ];
        $this->postJson($this->base($tenant)."/operations/{$invalidation['id']}/complete", ['fact' => $fact])
            ->assertOk()
            ->assertJsonPath('data.result.outcome', 'accepted');
        $this->postJson($this->base($tenant)."/operations/{$invalidation['id']}/complete", ['fact' => $fact])
            ->assertOk();

        $this->assertDatabaseHas('inventory_sales', [
            'tenant_id' => $tenant->id,
            'source_id' => '504',
            'status' => 'reversed',
        ]);
        $this->assertDatabaseHas('inventory_reservations', [
            'tenant_id' => $tenant->id,
            'idempotency_key' => 'issue-before-invalidation',
            'status' => 'reversed',
        ]);
        $this->assertSame(1.0, (float) $item->fresh()->stock_quantity);
        $this->assertDatabaseCount('inventory_movements', 2);
    }

    /** @return array{0:User,1:Tenant} */
    private function member(): array
    {
        $app = PlatformApp::query()->create([
            'key' => 'facturacion',
            'name' => 'Facturación',
            'host' => 'facturacion.stelfaro.com',
            'status' => 'active',
        ]);
        $tenant = Tenant::query()->create([
            'slug' => 'sync-'.fake()->unique()->slug(2),
            'name' => 'Empresa sincronizada',
            'status' => 'active',
            'primary_app_id' => $app->id,
        ]);
        $tenant->appAccesses()->create(['platform_app_id' => $app->id, 'status' => 'active', 'is_default' => true]);
        $user = User::factory()->create(['email_verified_at' => now(), 'must_change_password' => false]);
        $user->memberships()->create(['tenant_id' => $tenant->id, 'role' => 'owner', 'status' => 'active', 'is_default' => true]);

        return [$user, $tenant];
    }

    private function inventoryItem(Tenant $tenant, float $quantity): CatalogItem
    {
        $item = CatalogItem::query()->create([
            'tenant_id' => $tenant->id,
            'sku' => 'SYNC-ITEM',
            'name' => 'Repuesto sincronizado',
            'item_type' => 'part',
            'controls_inventory' => true,
            'base_price' => 25,
            'stock_quantity' => $quantity,
            'reference_cost' => 10,
            'status' => 'active',
        ]);
        InventoryLot::query()->create([
            'tenant_id' => $tenant->id,
            'core_sucursal_id' => 1,
            'core_sucursal_code' => 'M001',
            'core_sucursal_name' => 'Casa matriz',
            'catalog_item_id' => $item->id,
            'lot_code' => 'SYNC-LOT',
            'received_date' => '2026-07-20',
            'unit_cost' => 10,
            'initial_quantity' => $quantity,
            'available_quantity' => $quantity,
            'status' => 'active',
        ]);

        return $item;
    }

    /** @return array<string, mixed> */
    private function issuePayload(CatalogItem $item, string $key): array
    {
        return [
            'idempotency_key' => $key,
            'reservation' => [
                'core_sucursal_id' => 1,
                'core_sucursal_code' => 'M001',
                'core_sucursal_name' => 'Casa matriz',
                'lines' => [['catalog_item_id' => $item->id, 'quantity' => 1, 'description' => $item->name]],
            ],
            'sale' => [
                'core_sucursal_id' => 1,
                'core_sucursal_code' => 'M001',
                'core_sucursal_name' => 'Casa matriz',
                'source_type' => 'dte',
                'sale_date' => '2026-07-20',
                'metadata' => ['document_type' => '01'],
                'lines' => [[
                    'catalog_item_id' => $item->id,
                    'line_origin' => $item->controls_inventory ? 'inventory' : 'catalog',
                    'description' => $item->name,
                    'quantity' => 1,
                    'unit_price' => 25,
                    'net_total' => 25,
                ]],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function acceptedDte(int $id): array
    {
        return [
            'id' => $id,
            'estado' => 'accepted',
            'selloRecibido' => 'MH-SEAL-'.$id,
            'numeroControl' => 'DTE-01-M001P001-'.str_pad((string) $id, 15, '0', STR_PAD_LEFT),
            'codigoGeneracion' => 'GEN-'.$id,
            'tipoDte' => '01',
        ];
    }

    private function base(Tenant $tenant): string
    {
        return "/api/v1/platform/tenants/{$tenant->id}/fiscal-sync";
    }
}

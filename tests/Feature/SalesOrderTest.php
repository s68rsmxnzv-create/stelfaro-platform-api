<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\CatalogItem;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventorySale;
use App\Models\PlatformApp;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_delivery_records_inventory_sale_cash_and_receivable_without_duplicates(): void
    {
        [$user, $tenant] = $this->member();
        $item = CatalogItem::query()->create(['tenant_id' => $tenant->id, 'sku' => 'PAN-1', 'name' => 'Pantalla A54', 'item_type' => 'part', 'unit_code' => '59', 'taxable' => true, 'controls_inventory' => true, 'base_price' => 50, 'reference_cost' => 20, 'stock_quantity' => 2, 'status' => 'active']);
        InventoryLot::query()->create(['tenant_id' => $tenant->id, 'catalog_item_id' => $item->id, 'lot_code' => 'L-1', 'received_date' => today(), 'unit_cost' => 20, 'initial_quantity' => 2, 'available_quantity' => 2, 'status' => 'active']);
        $base = "/api/v1/platform/tenants/{$tenant->id}/sales-orders";

        $order = $this->actingAs($user)->postJson($base, [
            'idempotency_key' => 'order-1',
            'customer' => ['core_customer_id' => 77, 'name' => 'Cliente crédito', 'phone' => '7000-0000'],
            'lines' => [['catalog_item_id' => $item->id, 'quantity' => 1, 'unit_price' => 50]],
        ])->assertCreated()->assertJsonPath('data.number', 'OV-000001')->json('data');

        $this->postJson($base."/{$order['id']}/deliver", ['amount_received' => 20, 'method' => 'cash', 'document_choice' => 'order'])
            ->assertOk()->assertJsonPath('data.status', 'delivered')->assertJsonPath('data.paid_total', 20)->assertJsonPath('data.balance', 30);
        $this->postJson($base."/{$order['id']}/deliver", ['amount_received' => 20, 'method' => 'cash', 'document_choice' => 'order'])->assertOk();

        $this->assertSame(1.0, (float) $item->refresh()->stock_quantity);
        $this->assertSame(1, InventoryMovement::query()->where('reference_type', 'sales_order')->where('reference_id', (string) $order['id'])->count());
        $this->assertSame(1, InventorySale::query()->where('source_type', 'sales_order')->where('source_id', (string) $order['id'])->count());
        $this->assertDatabaseCount('sales_order_payments', 1);
        $this->assertDatabaseHas('cash_movements', ['sales_order_id' => $order['id'], 'amount' => 20, 'direction' => 'in']);
        $this->getJson($base.'?payment_status=receivable')->assertOk()->assertJsonCount(1, 'data');

        $this->postJson($base."/{$order['id']}/payments", ['idempotency_key' => 'payment-2', 'amount' => 30, 'method' => 'transfer'])
            ->assertOk()->assertJsonPath('data.balance', 0)->assertJsonPath('data.financial_status', 'settled');
        $this->postJson($base."/{$order['id']}/payments", ['idempotency_key' => 'payment-2', 'amount' => 30, 'method' => 'transfer'])
            ->assertOk()->assertJsonPath('data.balance', 0);
        $sale = InventorySale::query()->where('source_type', 'sales_order')->where('source_id', (string) $order['id'])->firstOrFail();
        $this->assertSame('paid', $sale->metadata['payment_status']);
        $this->assertSame(0, $sale->metadata['outstanding_amount']);
        $this->assertDatabaseCount('sales_order_payments', 2);
    }

    public function test_open_order_can_be_cancelled_without_affecting_inventory(): void
    {
        [$user, $tenant] = $this->member();
        $item = CatalogItem::query()->create(['tenant_id' => $tenant->id, 'name' => 'Servicio', 'item_type' => 'service', 'controls_inventory' => false, 'base_price' => 10, 'stock_quantity' => 0, 'status' => 'active']);
        $base = "/api/v1/platform/tenants/{$tenant->id}/sales-orders";
        $order = $this->actingAs($user)->postJson($base, ['idempotency_key' => 'cancel-1', 'customer' => ['name' => 'Cliente'], 'lines' => [['catalog_item_id' => $item->id, 'quantity' => 1, 'unit_price' => 10]]])->assertCreated()->json('data');

        $this->postJson($base."/{$order['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertDatabaseCount('inventory_sales', 0);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_delivered_order_is_linked_to_accepted_dte_without_duplicate_sale_or_cash(): void
    {
        [$user, $tenant] = $this->member();
        $item = CatalogItem::query()->create(['tenant_id' => $tenant->id, 'name' => 'Pantalla instalada', 'item_type' => 'part', 'controls_inventory' => false, 'base_price' => 50, 'stock_quantity' => 0, 'status' => 'active']);
        $base = "/api/v1/platform/tenants/{$tenant->id}/sales-orders";
        $order = $this->actingAs($user)->postJson($base, ['idempotency_key' => 'dte-order', 'customer' => ['core_customer_id' => 91, 'name' => 'Cliente DTE'], 'lines' => [['catalog_item_id' => $item->id, 'quantity' => 1, 'unit_price' => 50]]])->assertCreated()->json('data');
        $this->postJson($base."/{$order['id']}/deliver", ['amount_received' => 0, 'document_choice' => 'dte', 'dte_type' => '01'])->assertOk();

        $syncBase = "/api/v1/platform/tenants/{$tenant->id}/fiscal-sync";
        $operation = $this->postJson($syncBase.'/dte-issues', [
            'idempotency_key' => 'sales-order-dte',
            'sales_order_id' => $order['id'],
            'sale' => [
                'source_type' => 'sales_order', 'fiscal_document_type' => '01', 'net_amount' => 44.25, 'tax_amount' => 5.75, 'total_amount' => 50,
                'metadata' => ['document_type' => '01'],
                'lines' => [['catalog_item_id' => $item->id, 'line_origin' => 'catalog', 'description' => $item->name, 'quantity' => 1, 'unit_price' => 50, 'net_total' => 44.25, 'tax_amount' => 5.75, 'total_amount' => 50]],
            ],
        ])->assertCreated()->json('data');
        $fact = ['id' => 901, 'estado' => 'accepted', 'selloRecibido' => 'MH-901', 'numeroControl' => 'DTE-01-M001P001-000000000000901', 'codigoGeneracion' => 'GEN-901', 'tipoDte' => '01'];
        $this->postJson($syncBase."/operations/{$operation['id']}/complete", ['fact' => $fact])->assertOk();

        $this->assertDatabaseHas('sales_orders', ['id' => $order['id'], 'billing_status' => 'invoiced', 'core_dte_document_id' => 901]);
        $this->assertDatabaseCount('inventory_sales', 1);
        $sale = InventorySale::query()->where('source_type', 'sales_order')->where('source_id', (string) $order['id'])->firstOrFail();
        $this->assertSame(5.75, (float) $sale->tax_amount);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    private function member(): array
    {
        $app = PlatformApp::query()->create(['key' => 'facturacion', 'name' => 'Facturación', 'host' => 'facturacion.stelfaro.com', 'default_path' => '/', 'status' => 'active']);
        $tenant = Tenant::query()->create(['slug' => 'sales-orders', 'name' => 'Sales Orders', 'status' => 'active', 'primary_app_id' => $app->id]);
        $tenant->appAccesses()->create(['platform_app_id' => $app->id, 'status' => 'active', 'is_default' => true]);
        $user = User::factory()->create(['email_verified_at' => now(), 'must_change_password' => false]);
        $user->memberships()->create(['tenant_id' => $tenant->id, 'role' => 'company_admin', 'status' => 'active', 'is_default' => true]);
        $register = CashRegister::query()->create(['tenant_id' => $tenant->id, 'name' => 'Caja principal', 'status' => 'active']);
        CashSession::query()->create(['tenant_id' => $tenant->id, 'cash_register_id' => $register->id, 'opened_by' => $user->id, 'opening_balance' => 0, 'status' => 'open', 'opened_at' => now()]);

        return [$user, $tenant];
    }
}

<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\InventorySale;
use App\Models\PlatformApp;
use App\Models\ReceivableAccount;
use App\Models\SalesOrder;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommercialWorkOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_deposit_creates_cash_income_and_partial_receivable_without_duplicate_sale(): void
    {
        [$user, $tenant] = $this->member();
        $this->openCash($tenant, $user);

        $order = $this->actingAs($user)->postJson($this->base($tenant).'/sales-orders', [
            'title' => 'Ventana francesa',
            'customer' => ['id' => 81, 'name' => 'Cliente Vidriería'],
            'lines' => [['description' => 'Fabricación e instalación', 'quantity' => 1, 'unit_price' => 100]],
            'deposit' => ['amount' => 40, 'method' => 'cash'],
        ])->assertCreated()
            ->assertJsonPath('data.total', 100)
            ->assertJsonPath('data.paid_total', 40)
            ->assertJsonPath('data.balance', 60)
            ->json('data');

        $this->assertDatabaseHas('receivable_accounts', ['tenant_id' => $tenant->id, 'source_type' => 'sales_order', 'source_id' => $order['id'], 'status' => 'partial', 'balance' => 60]);
        $this->assertDatabaseHas('cash_movements', ['tenant_id' => $tenant->id, 'sales_order_id' => $order['id'], 'direction' => 'in', 'amount' => 40]);
        $this->assertDatabaseCount('inventory_sales', 0);
    }

    public function test_cancelling_order_cancels_receivable_and_records_refund_as_cash_output(): void
    {
        [$user, $tenant] = $this->member();
        $this->openCash($tenant, $user);
        $orderId = $this->actingAs($user)->postJson($this->base($tenant).'/sales-orders', [
            'title' => 'Puerta de vidrio',
            'customer' => ['name' => 'Ana'],
            'lines' => [['description' => 'Puerta', 'quantity' => 1, 'unit_price' => 100]],
            'deposit' => ['amount' => 50, 'method' => 'cash'],
        ])->json('data.id');

        $this->postJson($this->base($tenant)."/sales-orders/{$orderId}/cancel", [
            'reason' => 'El cliente desistió',
            'retained_amount' => 10,
            'method' => 'cash',
        ])->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.balance', 0);

        $this->assertDatabaseHas('receivable_accounts', ['source_type' => 'sales_order', 'source_id' => $orderId, 'status' => 'cancelled', 'balance' => 0]);
        $this->assertDatabaseHas('cash_movements', ['sales_order_id' => $orderId, 'direction' => 'out', 'amount' => 40]);
        $this->assertDatabaseHas('receivable_entries', ['entry_type' => 'cancellation', 'source_type' => 'sales_order', 'source_id' => $orderId]);
    }

    public function test_accepted_quotation_converts_to_order_with_traceable_deposit(): void
    {
        [$user, $tenant] = $this->member();
        $this->openCash($tenant, $user);
        $quotationId = $this->actingAs($user)->postJson($this->base($tenant).'/quotations', [
            'title' => 'Mueble a medida',
            'customer' => ['name' => 'Carlos'],
            'requested_deposit' => 25,
            'lines' => [['description' => 'Fabricación', 'quantity' => 1, 'unit_price' => 80]],
        ])->assertCreated()->json('data.id');

        $this->patchJson($this->base($tenant)."/quotations/{$quotationId}/status", ['status' => 'accepted'])->assertOk();
        $orderId = $this->postJson($this->base($tenant)."/quotations/{$quotationId}/convert", [
            'deposit' => ['amount' => 25, 'method' => 'cash'],
        ])->assertOk()->json('data.order_id');

        $this->assertDatabaseHas('sales_orders', ['id' => $orderId, 'quotation_id' => $quotationId, 'total' => 80]);
        $this->assertDatabaseHas('receivable_accounts', ['source_type' => 'sales_order', 'source_id' => $orderId, 'status' => 'partial', 'balance' => 55]);
        $this->assertDatabaseHas('quotations', ['id' => $quotationId, 'status' => 'converted']);
    }

    public function test_receivables_endpoint_unifies_orders_and_dte_balances(): void
    {
        [$user, $tenant] = $this->member();
        $orderId = $this->actingAs($user)->postJson($this->base($tenant).'/sales-orders', [
            'title' => 'Trabajo pendiente',
            'customer' => ['name' => 'Cliente de orden'],
            'lines' => [['description' => 'Servicio', 'quantity' => 1, 'unit_price' => 15]],
        ])->assertCreated()->json('data.id');
        InventorySale::query()->create([
            'tenant_id' => $tenant->id,
            'source_type' => 'dte',
            'source_id' => '182',
            'source_number' => 'DTE-01-M001P001-000000000000182',
            'sale_date' => today(),
            'operation_kind' => 'sale',
            'reporting_sign' => 1,
            'net_amount' => 10.62,
            'tax_amount' => 1.38,
            'total_amount' => 12,
            'status' => 'active',
            'metadata' => ['payment_status' => 'receivable', 'outstanding_amount' => 12, 'customer_name' => 'Consumidor Final'],
        ]);
        $settledOrderId = $this->postJson($this->base($tenant).'/sales-orders', [
            'title' => 'Trabajo ya pagado',
            'customer' => ['name' => 'Cliente sin saldo'],
            'lines' => [['description' => 'Servicio pagado', 'quantity' => 1, 'unit_price' => 20]],
        ])->assertCreated()->json('data.id');
        $settledOrder = SalesOrder::query()->findOrFail($settledOrderId);
        $settledOrder->forceFill(['financial_status' => 'settled'])->save();
        ReceivableAccount::query()
            ->where('source_type', 'sales_order')
            ->where('source_id', $settledOrderId)
            ->update(['paid_amount' => 20, 'balance' => 0, 'status' => 'settled', 'settled_at' => now()]);

        $this->getJson($this->base($tenant).'/receivables')
            ->assertOk()
            ->assertJsonPath('summary.open', 27)
            ->assertJsonPath('summary.accounts', 2)
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['source_type' => 'sales_order', 'source_id' => $orderId, 'balance' => 15])
            ->assertJsonFragment(['source_type' => 'dte', 'source_id' => 182, 'balance' => 12]);
    }

    private function base(Tenant $tenant): string
    {
        return "/api/v1/platform/tenants/{$tenant->id}";
    }

    /** @return array{User,Tenant} */
    private function member(): array
    {
        $app = PlatformApp::query()->create(['key' => 'facturacion', 'name' => 'Facturación', 'status' => 'active']);
        $tenant = Tenant::query()->create(['slug' => 'trabajos-comerciales', 'name' => 'Trabajos comerciales', 'status' => 'active', 'primary_app_id' => $app->id]);
        $tenant->appAccesses()->create(['platform_app_id' => $app->id, 'status' => 'active', 'is_default' => true]);
        $user = User::factory()->create();
        $user->memberships()->create(['tenant_id' => $tenant->id, 'role' => 'owner', 'status' => 'active', 'is_default' => true]);

        return [$user, $tenant];
    }

    private function openCash(Tenant $tenant, User $user): void
    {
        $register = CashRegister::query()->create(['tenant_id' => $tenant->id, 'name' => 'Caja principal', 'status' => 'active']);
        CashSession::query()->create(['tenant_id' => $tenant->id, 'cash_register_id' => $register->id, 'opened_by' => $user->id, 'opening_balance' => 0, 'status' => 'open', 'opened_at' => now()]);
    }
}

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

        $this->patchJson($this->base($tenant)."/quotations/{$quotationId}/status", ['status' => 'accepted', 'approval_method' => 'whatsapp', 'approval_note' => 'Confirmó el presupuesto.'])->assertOk();
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
            'due_at' => now()->subDays(35)->toISOString(),
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

        $this->getJson($this->base($tenant).'/receivables?aging=30')
            ->assertOk()
            ->assertJsonPath('summary.open', 15)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.source_id', $orderId);
    }

    public function test_payment_is_atomic_idempotent_and_cannot_exceed_locked_balance(): void
    {
        [$user, $tenant] = $this->member();
        $orderId = $this->actingAs($user)->postJson($this->base($tenant).'/sales-orders', ['title' => 'Trabajo', 'customer' => ['name' => 'Cliente'], 'lines' => [['description' => 'Servicio', 'quantity' => 1, 'unit_price' => 50]]])->json('data.id');
        $payload = ['amount' => 20, 'method' => 'cash', 'idempotency_key' => 'payment-safe-1'];
        $this->postJson($this->base($tenant)."/sales-orders/{$orderId}/payments", $payload)->assertUnprocessable();
        $this->assertDatabaseCount('sales_order_payments', 0);
        $this->openCash($tenant, $user);
        $this->postJson($this->base($tenant)."/sales-orders/{$orderId}/payments", $payload)->assertOk()->assertJsonPath('data.balance', 30);
        $this->postJson($this->base($tenant)."/sales-orders/{$orderId}/payments", $payload)->assertOk()->assertJsonPath('data.balance', 30);
        $this->assertDatabaseCount('sales_order_payments', 1);
        $this->assertDatabaseCount('cash_movements', 1);
    }

    public function test_order_accepts_zero_partial_or_full_deposit_but_rejects_overpayment(): void
    {
        [$user, $tenant] = $this->member();
        $this->openCash($tenant, $user);
        $base = ['title' => 'Producto encargado', 'customer' => ['name' => 'Cliente'], 'lines' => [['description' => 'Producto a fabricar', 'quantity' => 1, 'unit_price' => 100]]];

        $this->actingAs($user)->postJson($this->base($tenant).'/sales-orders', $base)->assertCreated()->assertJsonPath('data.balance', 100);
        $this->postJson($this->base($tenant).'/sales-orders', [...$base, 'idempotency_key' => 'partial-order', 'deposit' => ['amount' => 40, 'method' => 'cash']])->assertCreated()->assertJsonPath('data.balance', 60);
        $this->postJson($this->base($tenant).'/sales-orders', [...$base, 'idempotency_key' => 'paid-order', 'deposit' => ['amount' => 100, 'method' => 'cash']])->assertCreated()->assertJsonPath('data.balance', 0);
        $this->postJson($this->base($tenant).'/sales-orders', [...$base, 'idempotency_key' => 'overpaid-order', 'deposit' => ['amount' => 101, 'method' => 'cash']])->assertUnprocessable();

        $this->assertDatabaseMissing('sales_orders', ['idempotency_key' => 'overpaid-order']);
        $this->assertDatabaseCount('cash_movements', 2);
    }

    public function test_saving_a_quotation_never_creates_cash_or_receivable_movements(): void
    {
        [$user, $tenant] = $this->member();
        $this->openCash($tenant, $user);

        $this->actingAs($user)->postJson($this->base($tenant).'/quotations', [
            'title' => 'Propuesta sin cobro',
            'customer' => ['name' => 'Cliente'],
            'requested_deposit' => 40,
            'lines' => [['description' => 'Producto', 'quantity' => 1, 'unit_price' => 100]],
        ])->assertCreated()->assertJsonPath('data.requested_deposit', 40);

        $this->postJson($this->base($tenant).'/quotations', [
            'title' => 'Propuesta inválida',
            'customer' => ['name' => 'Cliente'],
            'requested_deposit' => 101,
            'lines' => [['description' => 'Producto', 'quantity' => 1, 'unit_price' => 100]],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseCount('receivable_accounts', 0);
        $this->assertDatabaseCount('inventory_sales', 0);
    }

    public function test_order_enforces_transitions_and_blocks_direct_cancellation_after_invoice(): void
    {
        [$user, $tenant] = $this->member();
        $orderId = $this->actingAs($user)->postJson($this->base($tenant).'/sales-orders', ['title' => 'Trabajo', 'customer' => ['name' => 'Cliente'], 'lines' => [['description' => 'Servicio', 'quantity' => 1, 'unit_price' => 25]]])->json('data.id');
        $this->patchJson($this->base($tenant)."/sales-orders/{$orderId}", ['status' => 'delivered'])->assertUnprocessable();
        foreach (['approved', 'in_progress', 'ready'] as $status) {
            $this->patchJson($this->base($tenant)."/sales-orders/{$orderId}", ['status' => $status])->assertOk();
        }
        $this->postJson($this->base($tenant)."/sales-orders/{$orderId}/invoice-link", ['core_dte_document_id' => 901, 'dte_number' => 'DTE-01-TEST', 'dte_generation_code' => 'GEN-901', 'dte_type' => '01'])->assertOk();
        $this->postJson($this->base($tenant)."/sales-orders/{$orderId}/cancel", ['reason' => 'Prueba'])->assertUnprocessable();
        $this->assertDatabaseCount('sales_order_status_events', 4);
    }

    public function test_quotation_can_be_edited_approved_duplicated_and_viewed_publicly(): void
    {
        [$user, $tenant] = $this->member();
        $quotation = $this->actingAs($user)->postJson($this->base($tenant).'/quotations', ['title' => 'Ventana', 'customer' => ['name' => 'Cliente'], 'lines' => [['description' => 'Ventana', 'quantity' => 1, 'unit_price' => 80]]])->assertCreated()->json('data');
        $this->putJson($this->base($tenant)."/quotations/{$quotation['id']}", ['title' => 'Ventana francesa', 'customer' => ['name' => 'Cliente'], 'lines' => [['description' => 'Ventana', 'quantity' => 1, 'unit_price' => 90]]])->assertOk()->assertJsonPath('data.total', 90);
        $this->patchJson($this->base($tenant)."/quotations/{$quotation['id']}/status", ['status' => 'accepted', 'approval_method' => 'whatsapp'])->assertOk();
        $this->postJson($this->base($tenant)."/quotations/{$quotation['id']}/duplicate")->assertCreated()->assertJsonPath('data.status', 'draft');
        $this->get($quotation['public_url'])->assertOk()->assertSee('Ventana francesa')->assertSee('Imprimir / guardar PDF');
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

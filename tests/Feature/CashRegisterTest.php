<?php

namespace Tests\Feature;

use App\Models\InventoryPurchase;
use App\Models\InventorySale;
use App\Models\PlatformApp;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashRegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_session_tracks_expense_and_closes_with_expected_balance(): void
    {
        [$user, $tenant] = $this->member();
        $base = "/api/v1/platform/tenants/{$tenant->id}/cash";
        $session = $this->actingAs($user)->postJson($base.'/sessions', ['opening_balance' => 100, 'name' => 'Caja principal'])
            ->assertCreated()->json('data');

        $movement = $this->postJson($base.'/movements', ['direction' => 'out', 'kind' => 'supplier_purchase', 'method' => 'cash', 'amount' => 25, 'description' => 'Pantalla de repuesto', 'expense_category' => 'replacement', 'destination' => 'direct_order', 'idempotency_key' => 'expense-1'])
            ->assertCreated()->assertJsonPath('data.expense.status', 'pending_document')->json('data');
        $this->postJson($base.'/movements', ['direction' => 'in', 'kind' => 'manual_income', 'method' => 'cash', 'amount' => 10, 'description' => 'Aporte', 'idempotency_key' => 'income-1'])->assertCreated();

        $this->getJson($base)->assertOk()->assertJsonPath('active_session.expected', 85)->assertJsonPath('summary.pending_documents', 1);
        $this->postJson($base."/sessions/{$session['id']}/close", ['declared_balance' => 84])->assertOk()->assertJsonPath('data.difference', -1);
        $this->assertDatabaseHas('cash_expenses', ['id' => $movement['expense']['id'], 'amount' => 25, 'status' => 'pending_document']);
    }

    public function test_expense_reconciliation_does_not_duplicate_cash_movement(): void
    {
        [$user, $tenant] = $this->member();
        $base = "/api/v1/platform/tenants/{$tenant->id}/cash";
        $this->actingAs($user)->postJson($base.'/sessions', ['opening_balance' => 0])->assertCreated();
        $expense = $this->postJson($base.'/movements', ['direction' => 'out', 'kind' => 'supplier_purchase', 'method' => 'cash', 'amount' => 30, 'description' => 'Repuesto', 'idempotency_key' => 'expense-2'])->assertCreated()->json('data.expense');
        $purchase = InventoryPurchase::query()->create(['tenant_id' => $tenant->id, 'purchase_number' => 1, 'document_type' => '03', 'purchase_date' => today(), 'subtotal' => 26.55, 'tax_amount' => 3.45, 'total' => 30, 'status' => 'received']);

        $this->postJson($base."/expenses/{$expense['id']}/reconcile", ['inventory_purchase_id' => $purchase->id])->assertOk()->assertJsonPath('data.status', 'reconciled')->assertJsonPath('data.difference', 0);
        $this->assertDatabaseCount('cash_movements', 1);
    }

    public function test_sales_report_uses_commercial_ledger_signs(): void
    {
        [$user, $tenant] = $this->member();
        InventorySale::query()->create(['tenant_id' => $tenant->id, 'source_type' => 'dte', 'source_id' => '1', 'sale_date' => today(), 'operation_kind' => 'sale', 'fiscal_document_type' => '01', 'reporting_sign' => 1, 'net_amount' => 100, 'tax_amount' => 13, 'total_amount' => 113, 'status' => 'active']);
        InventorySale::query()->create(['tenant_id' => $tenant->id, 'source_type' => 'dte', 'source_id' => '2', 'sale_date' => today(), 'operation_kind' => 'credit_note', 'fiscal_document_type' => '05', 'reporting_sign' => -1, 'net_amount' => 20, 'tax_amount' => 2.6, 'total_amount' => 22.6, 'status' => 'active']);

        $this->actingAs($user)->getJson("/api/v1/platform/tenants/{$tenant->id}/cash/sales-report")
            ->assertOk()->assertJsonPath('summary.transactions', 2)->assertJsonPath('summary.net', 80)->assertJsonPath('summary.total', 90.4);
    }

    private function member(): array
    {
        $app = PlatformApp::query()->create(['key' => 'facturacion', 'name' => 'Facturación', 'host' => 'facturacion.stelfaro.com', 'default_path' => '/', 'status' => 'active']);
        $tenant = Tenant::query()->create(['slug' => 'cash-company', 'name' => 'Cash Company', 'status' => 'active', 'primary_app_id' => $app->id]);
        $tenant->appAccesses()->create(['platform_app_id' => $app->id, 'status' => 'active', 'is_default' => true]);
        $user = User::factory()->create(['email_verified_at' => now(), 'must_change_password' => false]);
        $user->memberships()->create(['tenant_id' => $tenant->id, 'role' => 'company_admin', 'status' => 'active', 'is_default' => true]);

        return [$user, $tenant];
    }
}

<?php

namespace Tests\Feature;

use App\Models\CashExpense;
use App\Models\CashRegister;
use App\Models\CashRegisterSetting;
use App\Models\CashSession;
use App\Models\InventoryPurchase;
use App\Models\InventorySale;
use App\Models\PlatformApp;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkshopCustomer;
use App\Models\WorkshopDevice;
use App\Models\WorkshopOrder;
use App\Services\Cash\CashAutomationService;
use Carbon\CarbonImmutable;
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

    public function test_sales_report_includes_direct_costs_linked_to_workshop_orders(): void
    {
        [$user, $tenant] = $this->member();
        $customer = WorkshopCustomer::query()->create(['tenant_id' => $tenant->id, 'core_customer_id' => 42, 'name' => 'Cliente taller']);
        $device = WorkshopDevice::query()->create(['tenant_id' => $tenant->id, 'workshop_customer_id' => $customer->id, 'type' => 'phone', 'brand' => 'Samsung', 'model' => 'A54']);
        $order = WorkshopOrder::query()->create(['tenant_id' => $tenant->id, 'workshop_device_id' => $device->id, 'received_by' => $user->id, 'ticket_number' => 1, 'status' => 'delivered', 'reported_fault' => 'No carga', 'received_at' => now()]);
        InventorySale::query()->create([
            'tenant_id' => $tenant->id,
            'source_type' => 'workshop_order',
            'source_id' => (string) $order->id,
            'sale_date' => today(),
            'operation_kind' => 'sale',
            'reporting_sign' => 1,
            'net_amount' => 100,
            'tax_amount' => 0,
            'total_amount' => 100,
            'status' => 'active',
        ]);
        CashExpense::query()->create([
            'tenant_id' => $tenant->id,
            'workshop_order_id' => $order->id,
            'category' => 'replacement',
            'destination' => 'direct_order',
            'amount' => 35,
            'status' => 'pending_document',
            'description' => 'Repuesto para la orden',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)->getJson("/api/v1/platform/tenants/{$tenant->id}/cash/sales-report")
            ->assertOk()
            ->assertJsonPath('summary.cost', 35)
            ->assertJsonPath('summary.margin', 65)
            ->assertJsonPath('data.0.source_id', (string) $order->id);
    }

    public function test_cash_settings_are_scoped_to_a_branch(): void
    {
        [$user, $tenant] = $this->member();
        $payload = [
            'core_sucursal_id' => 21, 'core_sucursal_code' => 'S021', 'core_sucursal_name' => 'Centro', 'name' => 'Caja Centro',
            'timezone' => 'America/El_Salvador', 'default_opening_balance' => 40, 'carry_forward_balance' => true,
            'auto_open_enabled' => true, 'auto_open_time' => '08:00', 'auto_close_enabled' => true, 'auto_close_time' => '18:00',
            'close_grace_minutes' => 10, 'working_days' => [1, 2, 3, 4, 5, 6], 'non_working_dates' => [],
            'use_official_holidays' => false, 'allow_non_cash_when_closed' => true, 'active' => true,
        ];

        $this->actingAs($user)->postJson("/api/v1/platform/tenants/{$tenant->id}/cash/settings", $payload)
            ->assertOk()->assertJsonPath('data.core_sucursal_id', 21)->assertJsonPath('data.settings.default_opening_balance', 40);
        $this->assertDatabaseHas('cash_registers', ['tenant_id' => $tenant->id, 'core_sucursal_id' => 21]);
        $this->assertDatabaseCount('cash_register_settings', 1);
    }

    public function test_scheduler_opens_and_cuts_off_each_branch_idempotently(): void
    {
        [, $tenant] = $this->member();
        $register = CashRegister::query()->create(['tenant_id' => $tenant->id, 'core_sucursal_id' => 7, 'core_sucursal_code' => 'M007', 'core_sucursal_name' => 'Casa matriz', 'name' => 'Caja matriz', 'status' => 'active']);
        CashRegisterSetting::query()->create(['tenant_id' => $tenant->id, 'cash_register_id' => $register->id, 'timezone' => 'America/El_Salvador', 'default_opening_balance' => 25, 'carry_forward_balance' => false, 'auto_open_enabled' => true, 'auto_open_time' => '08:00', 'auto_close_enabled' => true, 'auto_close_time' => '18:00', 'close_grace_minutes' => 15, 'working_days' => [1, 2, 3, 4, 5, 6, 7], 'non_working_dates' => [], 'use_official_holidays' => false, 'allow_non_cash_when_closed' => true, 'active' => true]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-21 08:01:00', 'America/El_Salvador'));

        app(CashAutomationService::class)->process();
        app(CashAutomationService::class)->process();
        $this->assertDatabaseCount('cash_sessions', 1);
        $this->assertDatabaseHas('cash_sessions', ['cash_register_id' => $register->id, 'status' => 'open', 'opening_balance' => 25]);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-21 18:16:00', 'America/El_Salvador'));
        app(CashAutomationService::class)->process();
        $this->assertDatabaseHas('cash_sessions', ['cash_register_id' => $register->id, 'status' => 'closed_unverified', 'count_status' => 'pending_count', 'expected_balance' => 25]);
        CarbonImmutable::setTestNow();
    }

    public function test_scheduler_recovers_an_open_session_after_its_business_day_ended(): void
    {
        [, $tenant] = $this->member();
        $register = CashRegister::query()->create(['tenant_id' => $tenant->id, 'core_sucursal_id' => 7, 'core_sucursal_code' => 'M007', 'core_sucursal_name' => 'Casa matriz', 'name' => 'Caja matriz', 'status' => 'active']);
        CashRegisterSetting::query()->create(['tenant_id' => $tenant->id, 'cash_register_id' => $register->id, 'timezone' => 'America/El_Salvador', 'default_opening_balance' => 25, 'carry_forward_balance' => false, 'auto_open_enabled' => false, 'auto_open_time' => null, 'auto_close_enabled' => true, 'auto_close_time' => '20:00', 'close_grace_minutes' => 15, 'working_days' => [1, 2, 3, 4, 5], 'non_working_dates' => [], 'use_official_holidays' => false, 'allow_non_cash_when_closed' => true, 'active' => true]);
        CashSession::query()->create(['tenant_id' => $tenant->id, 'cash_register_id' => $register->id, 'business_date' => '2026-07-17', 'opening_balance' => 25, 'opening_source' => 'scheduled', 'status' => 'open', 'count_status' => 'pending', 'opened_at' => CarbonImmutable::parse('2026-07-17 08:00:00', 'America/El_Salvador')->utc()]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-19 09:00:00', 'America/El_Salvador'));

        $first = app(CashAutomationService::class)->process();
        $second = app(CashAutomationService::class)->process();

        $this->assertSame(1, $first['cutoff']);
        $this->assertSame(0, $second['cutoff']);
        $this->assertDatabaseHas('cash_sessions', ['cash_register_id' => $register->id, 'status' => 'closed_unverified', 'count_status' => 'pending_count', 'expected_balance' => 25]);
        CarbonImmutable::setTestNow();
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

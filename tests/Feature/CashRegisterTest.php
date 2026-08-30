<?php

namespace Tests\Feature;

use App\Models\CashExpense;
use App\Models\CashMovement;
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
use App\Services\Cash\CashService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashRegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_session_tracks_expense_and_closes_with_expected_balance(): void
    {
        [$user, $tenant] = $this->member();
        $base = "/api/v1/platform/tenants/{$tenant->id}/cash";
        $session = $this->actingAs($user)->postJson($base.'/sessions', ['opening_balance' => 100, 'name' => 'Caja principal', 'core_sucursal_id' => 1, 'core_sucursal_code' => 'M001', 'core_sucursal_name' => 'Casa matriz'])
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
        $this->actingAs($user)->postJson($base.'/sessions', ['opening_balance' => 0, 'core_sucursal_id' => 1, 'core_sucursal_code' => 'M001', 'core_sucursal_name' => 'Casa matriz'])->assertCreated();
        $expense = $this->postJson($base.'/movements', ['direction' => 'out', 'kind' => 'supplier_purchase', 'method' => 'cash', 'amount' => 30, 'description' => 'Repuesto', 'idempotency_key' => 'expense-2'])->assertCreated()->json('data.expense');
        $purchase = InventoryPurchase::query()->create(['tenant_id' => $tenant->id, 'purchase_number' => 1, 'document_type' => '03', 'purchase_date' => today(), 'subtotal' => 26.55, 'tax_amount' => 3.45, 'total' => 30, 'status' => 'received']);

        $this->postJson($base."/expenses/{$expense['id']}/reconcile", ['inventory_purchase_id' => $purchase->id])->assertOk()->assertJsonPath('data.status', 'reconciled')->assertJsonPath('data.difference', 0);
        $this->assertDatabaseCount('cash_movements', 1);
    }

    public function test_transfer_is_visible_in_the_session_without_changing_expected_cash(): void
    {
        [$user, $tenant] = $this->member();
        $base = "/api/v1/platform/tenants/{$tenant->id}/cash";
        $session = $this->actingAs($user)->postJson($base.'/sessions', ['opening_balance' => 100, 'name' => 'Caja principal', 'core_sucursal_id' => 1, 'core_sucursal_code' => 'M001', 'core_sucursal_name' => 'Casa matriz'])->assertCreated()->json('data');

        $this->postJson($base.'/movements', ['direction' => 'in', 'kind' => 'manual_income', 'method' => 'transfer', 'amount' => 50, 'description' => 'Cobro por transferencia', 'cash_register_id' => $session['register']['id'], 'idempotency_key' => 'transfer-1'])
            ->assertCreated();

        $this->getJson($base.'?cash_register_id='.$session['register']['id'])
            ->assertOk()
            ->assertJsonPath('active_session.expected', 100)
            ->assertJsonPath('summary.inflows', 50)
            ->assertJsonPath('data.0.method', 'transfer');
        $this->assertDatabaseHas('cash_movements', ['cash_session_id' => $session['id'], 'method' => 'transfer', 'amount' => 50]);
    }

    public function test_sales_report_uses_commercial_ledger_signs(): void
    {
        [$user, $tenant] = $this->member();
        InventorySale::query()->create(['tenant_id' => $tenant->id, 'source_type' => 'dte', 'source_id' => '1', 'sale_date' => today(), 'operation_kind' => 'sale', 'fiscal_document_type' => '01', 'reporting_sign' => 1, 'net_amount' => 100, 'tax_amount' => 13, 'total_amount' => 113, 'status' => 'active']);
        InventorySale::query()->create(['tenant_id' => $tenant->id, 'source_type' => 'dte', 'source_id' => '2', 'sale_date' => today(), 'operation_kind' => 'credit_note', 'fiscal_document_type' => '05', 'reporting_sign' => -1, 'net_amount' => 20, 'tax_amount' => 2.6, 'total_amount' => 22.6, 'status' => 'active']);

        $this->actingAs($user)->getJson("/api/v1/platform/tenants/{$tenant->id}/cash/sales-report")
            ->assertOk()->assertJsonPath('summary.transactions', 2)->assertJsonPath('summary.net', 80)->assertJsonPath('summary.total', 90.4);
    }

    public function test_dte_receivable_accepts_partial_idempotent_payments_and_updates_the_report(): void
    {
        [$user, $tenant] = $this->member();
        $base = "/api/v1/platform/tenants/{$tenant->id}/cash";
        $session = $this->actingAs($user)->postJson($base.'/sessions', ['opening_balance' => 20, 'name' => 'Caja principal', 'core_sucursal_id' => 1, 'core_sucursal_code' => 'M001', 'core_sucursal_name' => 'Casa matriz'])->assertCreated()->json('data');
        $sale = InventorySale::query()->create(['tenant_id' => $tenant->id, 'source_type' => 'dte', 'source_id' => '182', 'source_number' => 'DTE-01-M001P001-000000000000182', 'sale_date' => today(), 'operation_kind' => 'sale', 'fiscal_document_type' => '01', 'reporting_sign' => 1, 'net_amount' => 100, 'tax_amount' => 13, 'total_amount' => 113, 'status' => 'active', 'metadata' => ['payment_status' => 'receivable', 'outstanding_amount' => 113, 'customer_name' => 'Cliente crédito']]);
        $url = $base."/sales/{$sale->id}/payments";

        $this->postJson($url, ['amount' => 40, 'method' => 'transfer', 'reference' => 'TRX-40', 'idempotency_key' => 'payment-transfer'])
            ->assertCreated()->assertJsonPath('data.outstanding_amount', 73)->assertJsonPath('data.payment_status', 'receivable');
        $this->postJson($url, ['amount' => 40, 'method' => 'transfer', 'reference' => 'TRX-40', 'idempotency_key' => 'payment-transfer'])
            ->assertOk()->assertJsonPath('data.outstanding_amount', 73)->assertJsonPath('data.created', false);
        $this->postJson($url, ['amount' => 73, 'method' => 'cash', 'idempotency_key' => 'payment-cash'])
            ->assertCreated()->assertJsonPath('data.outstanding_amount', 0)->assertJsonPath('data.payment_status', 'paid');

        $this->assertDatabaseCount('cash_movements', 2);
        $this->getJson($base.'?cash_register_id='.$session['register']['id'])->assertOk()->assertJsonPath('active_session.expected', 93);
        $this->getJson($base.'/sales-report')->assertOk()
            ->assertJsonPath('summary.payments.cash', 73)
            ->assertJsonPath('summary.payments.transfer', 40)
            ->assertJsonPath('summary.receivable', 0)
            ->assertJsonPath('data.0.payment_status', 'paid');
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

    public function test_opening_a_session_without_a_resolvable_branch_fails_with_a_clear_error(): void
    {
        [$user, $tenant] = $this->member();

        $this->actingAs($user)->postJson("/api/v1/platform/tenants/{$tenant->id}/cash/sessions", ['opening_balance' => 50])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cash_register');
    }

    public function test_default_register_is_identified_by_branch_not_by_name(): void
    {
        [, $tenant] = $this->member();

        $first = app(CashService::class)->defaultRegister($tenant, ['core_sucursal_id' => 3, 'name' => 'Caja A']);
        $second = app(CashService::class)->defaultRegister($tenant, ['core_sucursal_id' => 3, 'name' => 'Caja B']);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('cash_registers', 1);
    }

    public function test_cashier_can_only_open_the_cash_register_of_their_assigned_branch(): void
    {
        [$user, $tenant] = $this->cashierMember(assignedSucursalId: 5);
        $base = "/api/v1/platform/tenants/{$tenant->id}/cash";

        $this->actingAs($user)->postJson($base.'/sessions', ['opening_balance' => 50, 'core_sucursal_id' => 9, 'core_sucursal_code' => 'S009', 'core_sucursal_name' => 'Otra sucursal'])
            ->assertForbidden();

        $this->actingAs($user)->postJson($base.'/sessions', ['opening_balance' => 50, 'core_sucursal_id' => 5, 'core_sucursal_code' => 'S005', 'core_sucursal_name' => 'Mi sucursal'])
            ->assertCreated();
    }

    public function test_cashier_opening_a_session_without_specifying_a_branch_uses_their_assigned_branch(): void
    {
        [$user, $tenant] = $this->cashierMember(assignedSucursalId: 5);
        $base = "/api/v1/platform/tenants/{$tenant->id}/cash";

        $session = $this->actingAs($user)->postJson($base.'/sessions', ['opening_balance' => 50])
            ->assertCreated()->json('data');

        $this->assertSame(5, $session['register']['branch_id']);
    }

    public function test_cashier_cannot_close_a_cash_register_of_another_branch(): void
    {
        [$user, $tenant] = $this->cashierMember(assignedSucursalId: 5);
        $register = CashRegister::query()->create(['tenant_id' => $tenant->id, 'core_sucursal_id' => 9, 'core_sucursal_code' => 'S009', 'core_sucursal_name' => 'Otra sucursal', 'name' => 'Caja otra sucursal', 'status' => 'active']);
        $session = CashSession::query()->create(['tenant_id' => $tenant->id, 'cash_register_id' => $register->id, 'opening_balance' => 0, 'business_date' => today(), 'opening_source' => 'manual', 'count_status' => 'pending', 'status' => 'open', 'opened_at' => now()]);

        $this->actingAs($user)->postJson("/api/v1/platform/tenants/{$tenant->id}/cash/sessions/{$session->id}/close", ['declared_balance' => 0])
            ->assertForbidden();
    }

    public function test_cashier_cannot_register_a_movement_against_another_branchs_cash_register(): void
    {
        [$user, $tenant] = $this->cashierMember(assignedSucursalId: 5);
        $register = CashRegister::query()->create(['tenant_id' => $tenant->id, 'core_sucursal_id' => 9, 'core_sucursal_code' => 'S009', 'core_sucursal_name' => 'Otra sucursal', 'name' => 'Caja otra sucursal', 'status' => 'active']);
        CashSession::query()->create(['tenant_id' => $tenant->id, 'cash_register_id' => $register->id, 'opening_balance' => 0, 'business_date' => today(), 'opening_source' => 'manual', 'count_status' => 'pending', 'status' => 'open', 'opened_at' => now()]);

        $this->actingAs($user)->postJson("/api/v1/platform/tenants/{$tenant->id}/cash/movements", [
            'direction' => 'in', 'kind' => 'manual_income', 'method' => 'cash', 'amount' => 10,
            'description' => 'Intento ajeno', 'idempotency_key' => 'ajeno-1', 'cash_register_id' => $register->id,
        ])->assertForbidden();
    }

    public function test_cashier_without_a_fiscal_assignment_cannot_operate_any_cash_register(): void
    {
        [$user, $tenant] = $this->cashierMember(assignedSucursalId: null);

        $this->actingAs($user)->postJson("/api/v1/platform/tenants/{$tenant->id}/cash/sessions", ['opening_balance' => 50, 'core_sucursal_id' => 1, 'core_sucursal_code' => 'M001', 'core_sucursal_name' => 'Casa matriz'])
            ->assertForbidden();
    }

    public function test_cashier_only_sees_the_cash_register_of_their_assigned_branch_in_the_overview(): void
    {
        [$user, $tenant] = $this->cashierMember(assignedSucursalId: 5);
        CashRegister::query()->create(['tenant_id' => $tenant->id, 'core_sucursal_id' => 5, 'core_sucursal_code' => 'S005', 'core_sucursal_name' => 'Mi sucursal', 'name' => 'Caja mía', 'status' => 'active']);
        CashRegister::query()->create(['tenant_id' => $tenant->id, 'core_sucursal_id' => 9, 'core_sucursal_code' => 'S009', 'core_sucursal_name' => 'Otra sucursal', 'name' => 'Caja ajena', 'status' => 'active']);

        $this->actingAs($user)->getJson("/api/v1/platform/tenants/{$tenant->id}/cash")
            ->assertOk()
            ->assertJsonCount(1, 'registers')
            ->assertJsonPath('registers.0.branch_id', 5);
    }

    public function test_owner_sees_consolidated_cash_status_by_branch(): void
    {
        [$user, $tenant] = $this->member();
        $matriz = CashRegister::query()->create(['tenant_id' => $tenant->id, 'core_sucursal_id' => 1, 'core_sucursal_code' => 'M001', 'core_sucursal_name' => 'Casa matriz', 'name' => 'Caja matriz', 'status' => 'active']);
        CashSession::query()->create(['tenant_id' => $tenant->id, 'cash_register_id' => $matriz->id, 'opened_by' => $user->id, 'opening_balance' => 50, 'business_date' => today(), 'opening_source' => 'manual', 'count_status' => 'pending', 'status' => 'open', 'opened_at' => now()]);
        CashRegister::query()->create(['tenant_id' => $tenant->id, 'core_sucursal_id' => 2, 'core_sucursal_code' => 'S002', 'core_sucursal_name' => 'Sucursal Centro', 'name' => 'Caja Centro', 'status' => 'active']);

        $this->actingAs($user)->getJson("/api/v1/platform/tenants/{$tenant->id}/cash/consolidated")
            ->assertOk()
            ->assertJsonPath('has_multiple_branches', true)
            ->assertJsonPath('data.0.branch_name', 'Casa matriz')
            ->assertJsonPath('data.0.status', 'open')
            ->assertJsonPath('data.0.balance', 50)
            ->assertJsonPath('data.0.opened_by', $user->name)
            ->assertJsonPath('data.1.branch_name', 'Sucursal Centro')
            ->assertJsonPath('data.1.status', 'closed')
            ->assertJsonPath('data.1.balance', null);
    }

    public function test_cashier_cannot_view_the_consolidated_cash_status(): void
    {
        [$cashier, $tenant] = $this->cashierMember(assignedSucursalId: 1);

        $this->actingAs($cashier)->getJson("/api/v1/platform/tenants/{$tenant->id}/cash/consolidated")->assertForbidden();
    }

    public function test_cashier_cannot_read_another_branchs_cash_data_through_the_overview(): void
    {
        [$user, $tenant] = $this->cashierMember(assignedSucursalId: 5);
        $mine = CashRegister::query()->create(['tenant_id' => $tenant->id, 'core_sucursal_id' => 5, 'core_sucursal_code' => 'S005', 'core_sucursal_name' => 'Mi sucursal', 'name' => 'Caja mía', 'status' => 'active']);
        $other = CashRegister::query()->create(['tenant_id' => $tenant->id, 'core_sucursal_id' => 9, 'core_sucursal_code' => 'S009', 'core_sucursal_name' => 'Otra sucursal', 'name' => 'Caja ajena', 'status' => 'active']);
        $otherSession = CashSession::query()->create(['tenant_id' => $tenant->id, 'cash_register_id' => $other->id, 'opening_balance' => 500, 'business_date' => today(), 'opening_source' => 'manual', 'count_status' => 'pending', 'status' => 'open', 'opened_at' => now()]);
        CashSession::query()->create(['tenant_id' => $tenant->id, 'cash_register_id' => $other->id, 'opening_balance' => 10, 'business_date' => today()->subDay(), 'opening_source' => 'manual', 'count_status' => 'pending_count', 'status' => 'closed_unverified', 'expected_balance' => 10, 'opened_at' => now()->subDay()]);
        CashMovement::query()->create(['tenant_id' => $tenant->id, 'cash_register_id' => $other->id, 'cash_session_id' => $otherSession->id, 'direction' => 'in', 'kind' => 'manual_income', 'method' => 'cash', 'amount' => 777, 'description' => 'Ingreso ajeno', 'idempotency_key' => 'ajeno-overview', 'created_by' => $user->id, 'occurred_at' => now()]);
        $base = "/api/v1/platform/tenants/{$tenant->id}/cash";

        // Sin filtro explícito: nada de la otra sucursal (movimientos, resumen, cortes, sesión activa).
        $this->actingAs($user)->getJson($base)->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('summary.inflows', 0)
            ->assertJsonCount(0, 'pending_counts')
            ->assertJsonPath('active_session', null);
        // Con filtro explícito a una caja ajena: 403.
        $this->actingAs($user)->getJson($base.'?cash_register_id='.$other->id)->assertForbidden();
        // Su propia caja sigue siendo consultable.
        $this->actingAs($user)->getJson($base.'?cash_register_id='.$mine->id)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_cashier_overview_never_shows_an_open_session_from_another_branch(): void
    {
        [$user, $tenant] = $this->cashierMember(assignedSucursalId: 5);
        CashRegister::query()->create(['tenant_id' => $tenant->id, 'core_sucursal_id' => 5, 'core_sucursal_code' => 'S005', 'core_sucursal_name' => 'Mi sucursal', 'name' => 'Caja mía', 'status' => 'active']);
        $other = CashRegister::query()->create(['tenant_id' => $tenant->id, 'core_sucursal_id' => 9, 'core_sucursal_code' => 'S009', 'core_sucursal_name' => 'Otra sucursal', 'name' => 'Caja ajena', 'status' => 'active']);
        CashSession::query()->create(['tenant_id' => $tenant->id, 'cash_register_id' => $other->id, 'opened_by' => $user->id, 'opening_balance' => 500, 'business_date' => today(), 'opening_source' => 'manual', 'count_status' => 'pending', 'status' => 'open', 'opened_at' => now()]);

        $this->actingAs($user)->getJson("/api/v1/platform/tenants/{$tenant->id}/cash")->assertOk()->assertJsonPath('active_session', null);
    }

    public function test_a_cash_register_cannot_hold_two_open_sessions(): void
    {
        [$user, $tenant] = $this->member();
        $register = CashRegister::query()->create(['tenant_id' => $tenant->id, 'core_sucursal_id' => 4, 'core_sucursal_code' => 'S004', 'core_sucursal_name' => 'Sucursal', 'name' => 'Caja', 'status' => 'active']);
        $attributes = ['tenant_id' => $tenant->id, 'cash_register_id' => $register->id, 'opened_by' => $user->id, 'opening_balance' => 0, 'business_date' => today(), 'opening_source' => 'manual', 'count_status' => 'pending', 'status' => 'open', 'opened_at' => now()];
        CashSession::query()->create($attributes);

        $this->expectException(QueryException::class);
        CashSession::query()->create([...$attributes, 'business_date' => today()->addDay()]);
    }

    public function test_a_session_cannot_be_opened_on_an_inactive_cash_register(): void
    {
        [$user, $tenant] = $this->member();
        $register = CashRegister::query()->create(['tenant_id' => $tenant->id, 'core_sucursal_id' => 6, 'core_sucursal_code' => 'S006', 'core_sucursal_name' => 'Sucursal cerrada', 'name' => 'Caja desactivada', 'status' => 'inactive']);

        $this->actingAs($user)->postJson("/api/v1/platform/tenants/{$tenant->id}/cash/sessions", ['opening_balance' => 10, 'cash_register_id' => $register->id])
            ->assertStatus(422)->assertJsonValidationErrors('cash_register');
        $this->assertDatabaseCount('cash_sessions', 0);
    }

    public function test_cash_settings_upsert_reports_a_validation_error_instead_of_failing_on_a_duplicate_active_branch(): void
    {
        [$user, $tenant] = $this->member();
        CashRegister::query()->create(['tenant_id' => $tenant->id, 'core_sucursal_id' => 5, 'core_sucursal_code' => 'S005', 'core_sucursal_name' => 'Centro', 'name' => 'Caja activa', 'status' => 'active']);
        CashRegister::query()->create(['tenant_id' => $tenant->id, 'core_sucursal_id' => 5, 'core_sucursal_code' => 'S005', 'core_sucursal_name' => 'Centro', 'name' => 'Caja histórica', 'status' => 'inactive']);
        $payload = [
            'core_sucursal_id' => 5, 'core_sucursal_code' => 'S005', 'core_sucursal_name' => 'Centro', 'name' => 'Caja Centro',
            'timezone' => 'America/El_Salvador', 'default_opening_balance' => 0, 'carry_forward_balance' => true,
            'auto_open_enabled' => false, 'auto_close_enabled' => false, 'close_grace_minutes' => 10,
            'working_days' => [1, 2, 3, 4, 5], 'non_working_dates' => [], 'use_official_holidays' => false,
            'allow_non_cash_when_closed' => true, 'active' => true,
        ];

        // Resuelve de forma determinista la caja YA activa, así que activar es idempotente.
        $this->actingAs($user)->postJson("/api/v1/platform/tenants/{$tenant->id}/cash/settings", $payload)
            ->assertOk()->assertJsonPath('data.name', 'Caja Centro');
        $this->assertDatabaseHas('cash_registers', ['name' => 'Caja histórica', 'status' => 'inactive']);
    }

    public function test_reactivating_a_second_cash_register_of_the_same_branch_fails_with_a_validation_error(): void
    {
        [$user, $tenant] = $this->member();
        CashRegister::query()->create(['tenant_id' => $tenant->id, 'core_sucursal_id' => 5, 'core_sucursal_code' => 'S005', 'core_sucursal_name' => 'Centro', 'name' => 'Caja activa', 'status' => 'active']);
        $inactive = CashRegister::query()->create(['tenant_id' => $tenant->id, 'core_sucursal_id' => 5, 'core_sucursal_code' => 'S005', 'core_sucursal_name' => 'Centro', 'name' => 'Caja histórica', 'status' => 'inactive']);

        $this->actingAs($user)->putJson("/api/v1/platform/tenants/{$tenant->id}/cash/settings/{$inactive->id}", [
            'name' => 'Caja histórica', 'timezone' => 'America/El_Salvador', 'default_opening_balance' => 0, 'carry_forward_balance' => true,
            'auto_open_enabled' => false, 'auto_close_enabled' => false, 'close_grace_minutes' => 10,
            'working_days' => [1, 2, 3, 4, 5], 'non_working_dates' => [], 'use_official_holidays' => false,
            'allow_non_cash_when_closed' => true, 'active' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('core_sucursal_id');
        $this->assertDatabaseHas('cash_registers', ['id' => $inactive->id, 'status' => 'inactive']);
    }

    public function test_cashier_can_close_the_cash_register_of_their_own_branch(): void
    {
        [$user, $tenant] = $this->cashierMember(assignedSucursalId: 5);
        $base = "/api/v1/platform/tenants/{$tenant->id}/cash";
        $session = $this->actingAs($user)->postJson($base.'/sessions', ['opening_balance' => 40])->assertCreated()->json('data');

        $this->actingAs($user)->postJson($base."/sessions/{$session['id']}/close", ['declared_balance' => 40])
            ->assertOk()->assertJsonPath('data.difference', 0);
    }

    public function test_cashier_can_reverse_a_movement_of_their_branch_but_not_of_another(): void
    {
        [$user, $tenant] = $this->cashierMember(assignedSucursalId: 5);
        $base = "/api/v1/platform/tenants/{$tenant->id}/cash";
        $this->actingAs($user)->postJson($base.'/sessions', ['opening_balance' => 0])->assertCreated();
        $mine = $this->postJson($base.'/movements', ['direction' => 'in', 'kind' => 'manual_income', 'method' => 'cash', 'amount' => 15, 'description' => 'Aporte', 'idempotency_key' => 'mine-1'])->assertCreated()->json('data');

        $this->postJson($base."/movements/{$mine['id']}/reverse", ['reason' => 'Error de digitación'])
            ->assertOk()->assertJsonPath('data.direction', 'out')->assertJsonPath('data.amount', 15);

        $other = CashRegister::query()->create(['tenant_id' => $tenant->id, 'core_sucursal_id' => 9, 'core_sucursal_code' => 'S009', 'core_sucursal_name' => 'Otra sucursal', 'name' => 'Caja ajena', 'status' => 'active']);
        $alien = CashMovement::query()->create(['tenant_id' => $tenant->id, 'cash_register_id' => $other->id, 'direction' => 'in', 'kind' => 'manual_income', 'method' => 'cash', 'amount' => 99, 'description' => 'Ingreso ajeno', 'idempotency_key' => 'alien-1', 'created_by' => $user->id, 'occurred_at' => now()]);

        $this->postJson($base."/movements/{$alien->id}/reverse", ['reason' => 'Intento ajeno'])->assertForbidden();
    }

    public function test_cashier_completes_the_open_move_close_cycle_on_their_own_branch(): void
    {
        [$user, $tenant] = $this->cashierMember(assignedSucursalId: 5);
        $base = "/api/v1/platform/tenants/{$tenant->id}/cash";

        $session = $this->actingAs($user)->postJson($base.'/sessions', ['opening_balance' => 100])->assertCreated()->json('data');
        $this->assertSame(5, $session['register']['branch_id']);
        $this->postJson($base.'/movements', ['direction' => 'in', 'kind' => 'manual_income', 'method' => 'cash', 'amount' => 20, 'description' => 'Venta mostrador', 'idempotency_key' => 'ciclo-1'])->assertCreated();

        $this->getJson($base)->assertOk()->assertJsonPath('active_session.id', $session['id'])->assertJsonPath('active_session.expected', 120);
        $this->postJson($base."/sessions/{$session['id']}/close", ['declared_balance' => 120])->assertOk()->assertJsonPath('data.difference', 0);
        $this->assertDatabaseHas('cash_sessions', ['id' => $session['id'], 'status' => 'closed']);
    }

    public function test_history_lists_closed_sessions_with_pagination_and_date_filter(): void
    {
        [$user, $tenant] = $this->member();
        $register = CashRegister::query()->create(['tenant_id' => $tenant->id, 'core_sucursal_id' => 1, 'core_sucursal_code' => 'M001', 'core_sucursal_name' => 'Casa matriz', 'name' => 'Caja matriz', 'status' => 'active']);
        CashSession::query()->create(['tenant_id' => $tenant->id, 'cash_register_id' => $register->id, 'opened_by' => $user->id, 'closed_by' => $user->id, 'opening_balance' => 50, 'expected_balance' => 80, 'declared_balance' => 80, 'difference' => 0, 'business_date' => '2026-08-10', 'opening_source' => 'manual', 'count_status' => 'counted', 'status' => 'closed', 'opened_at' => now(), 'closed_at' => now()]);
        CashSession::query()->create(['tenant_id' => $tenant->id, 'cash_register_id' => $register->id, 'opened_by' => $user->id, 'opening_balance' => 40, 'expected_balance' => 95, 'declared_balance' => null, 'difference' => null, 'business_date' => '2026-08-20', 'opening_source' => 'manual', 'count_status' => 'pending_count', 'status' => 'closed_unverified', 'opened_at' => now(), 'closed_at' => now()]);
        CashSession::query()->create(['tenant_id' => $tenant->id, 'cash_register_id' => $register->id, 'opened_by' => $user->id, 'opening_balance' => 0, 'business_date' => today(), 'opening_source' => 'manual', 'count_status' => 'pending', 'status' => 'open', 'opened_at' => now()]);

        $response = $this->actingAs($user)->getJson("/api/v1/platform/tenants/{$tenant->id}/cash/history")
            ->assertOk()->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.business_date', '2026-08-20')
            ->assertJsonPath('data.0.status', 'closed_unverified')
            ->assertJsonPath('data.0.declared_balance', null)
            ->assertJsonPath('data.1.business_date', '2026-08-10')
            ->assertJsonPath('data.1.status', 'closed')
            ->assertJsonPath('data.1.declared_balance', 80)
            ->assertJsonPath('data.1.difference', 0)
            ->assertJsonPath('data.1.opened_by', $user->name)
            ->assertJsonPath('data.1.closed_by', $user->name);

        $this->getJson("/api/v1/platform/tenants/{$tenant->id}/cash/history?date_from=2026-08-15")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.business_date', '2026-08-20');
    }

    public function test_cashier_cannot_request_history_of_another_branch(): void
    {
        [$user, $tenant] = $this->cashierMember(assignedSucursalId: 5);
        $register = CashRegister::query()->create(['tenant_id' => $tenant->id, 'core_sucursal_id' => 9, 'core_sucursal_code' => 'S009', 'core_sucursal_name' => 'Otra sucursal', 'name' => 'Caja otra sucursal', 'status' => 'active']);
        CashSession::query()->create(['tenant_id' => $tenant->id, 'cash_register_id' => $register->id, 'opened_by' => $user->id, 'opening_balance' => 0, 'declared_balance' => 0, 'difference' => 0, 'business_date' => '2026-08-10', 'opening_source' => 'manual', 'count_status' => 'counted', 'status' => 'closed', 'opened_at' => now(), 'closed_at' => now()]);

        $this->actingAs($user)->getJson("/api/v1/platform/tenants/{$tenant->id}/cash/history?cash_register_id={$register->id}")
            ->assertForbidden();
        $this->getJson("/api/v1/platform/tenants/{$tenant->id}/cash/history")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_consolidated_includes_up_to_five_recent_closures_per_branch(): void
    {
        [$user, $tenant] = $this->member();
        $register = CashRegister::query()->create(['tenant_id' => $tenant->id, 'core_sucursal_id' => 1, 'core_sucursal_code' => 'M001', 'core_sucursal_name' => 'Casa matriz', 'name' => 'Caja matriz', 'status' => 'active']);
        foreach (range(1, 6) as $day) {
            CashSession::query()->create(['tenant_id' => $tenant->id, 'cash_register_id' => $register->id, 'opened_by' => $user->id, 'closed_by' => $user->id, 'opening_balance' => 10, 'expected_balance' => 20, 'declared_balance' => 20, 'difference' => 0, 'business_date' => sprintf('2026-08-%02d', $day), 'opening_source' => 'manual', 'count_status' => 'counted', 'status' => 'closed', 'opened_at' => now(), 'closed_at' => now()]);
        }

        $consolidated = app(\App\Services\Cash\CashService::class)->consolidated($tenant);

        $this->assertCount(5, $consolidated->first()['recent_closures']);
        $this->assertSame('2026-08-06', $consolidated->first()['recent_closures'][0]['business_date']);
    }

    private function member(): array
    {
        $app = PlatformApp::query()->create(['key' => 'facturacion', 'name' => 'Facturación', 'host' => 'new.stelfaro.com', 'default_path' => '/', 'status' => 'active']);
        $tenant = Tenant::query()->create(['slug' => 'cash-company', 'name' => 'Cash Company', 'status' => 'active', 'primary_app_id' => $app->id]);
        $tenant->appAccesses()->create(['platform_app_id' => $app->id, 'status' => 'active', 'is_default' => true]);
        $user = User::factory()->create(['email_verified_at' => now(), 'must_change_password' => false]);
        $user->memberships()->create(['tenant_id' => $tenant->id, 'role' => 'company_admin', 'status' => 'active', 'is_default' => true]);

        return [$user, $tenant];
    }

    private function cashierMember(?int $assignedSucursalId): array
    {
        $app = PlatformApp::query()->create(['key' => 'facturacion', 'name' => 'Facturación', 'host' => 'new.stelfaro.com', 'default_path' => '/', 'status' => 'active']);
        $tenant = Tenant::query()->create(['slug' => 'cash-cashier-company', 'name' => 'Cash Cashier Company', 'status' => 'active', 'primary_app_id' => $app->id]);
        $tenant->appAccesses()->create(['platform_app_id' => $app->id, 'status' => 'active', 'is_default' => true]);
        $user = User::factory()->create(['email_verified_at' => now(), 'must_change_password' => false]);
        $membership = $user->memberships()->create(['tenant_id' => $tenant->id, 'role' => 'billing_user', 'status' => 'active', 'is_default' => true]);
        if ($assignedSucursalId !== null) {
            $membership->fiscalAssignments()->create(['core_empresa_id' => 900, 'core_sucursal_id' => $assignedSucursalId, 'core_punto_venta_id' => 1, 'is_default' => true, 'status' => 'active']);
        }

        return [$user, $tenant];
    }
}

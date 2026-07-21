<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\PlatformApp;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkshopDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkshopOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_receive_device_with_shared_customer_and_advance(): void
    {
        [$user, $tenant] = $this->member();
        $response = $this->actingAs($user)->postJson("/api/v1/platform/tenants/{$tenant->id}/workshop/orders", [
            'customer' => ['core_customer_id' => 44, 'name' => 'Ana López', 'phone' => '7000-0000'],
            'device' => ['type' => 'phone', 'brand' => 'Apple', 'model' => 'iPhone 13', 'imei' => '490154203237518', 'power_status' => 'on', 'functional_tests' => ['display' => 'passed'], 'is_locked' => true, 'access_type' => 'pattern', 'access_secret' => '1-2-5-8'],
            'reported_fault' => 'No enciende',
            'physical_condition' => 'Golpe en esquina inferior',
            'accessories' => ['Cargador'],
            'estimated_total' => 100,
            'advance' => ['amount' => 20, 'method' => 'cash'],
        ]);

        $response->assertCreated()->assertJsonPath('data.ticket', 'T-000001')->assertJsonPath('data.paid_total', 20);
        $response->assertJsonPath('data.balance', 80);
        $this->assertDatabaseHas('workshop_customers', ['tenant_id' => $tenant->id, 'core_customer_id' => 44]);
        $this->assertDatabaseHas('workshop_order_payments', ['tenant_id' => $tenant->id, 'amount' => 20]);
        $this->assertDatabaseMissing('workshop_devices', ['access_secret' => '1-2-5-8']);
        $this->assertSame('1-2-5-8', WorkshopDevice::query()->firstOrFail()->access_secret);
        $this->getJson("/api/v1/platform/tenants/{$tenant->id}/workshop/orders?q=Ana&status=received&per_page=5")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('stats.received', 1)
            ->assertJsonPath('data.0.photo_count', 0);
    }

    public function test_multiple_devices_share_one_reception_without_sharing_their_workflow(): void
    {
        [$user, $tenant] = $this->member();
        $base = "/api/v1/platform/tenants/{$tenant->id}/workshop/orders";
        $first = $this->actingAs($user)->postJson($base, [
            'customer' => ['core_customer_id' => 55, 'name' => 'Cliente con dos equipos', 'phone' => '7000-0055'],
            'core_sucursal_id' => 5, 'core_sucursal_code' => 'M001', 'core_sucursal_name' => 'Casa matriz',
            'device' => ['type' => 'phone', 'brand' => 'Samsung', 'model' => 'A54', 'power_status' => 'on'],
            'reported_fault' => 'Pantalla quebrada',
        ])->assertCreated()->assertJsonPath('data.ticket', 'T-000001')->assertJsonPath('data.reception.sequence', 1)->json('data');

        $second = $this->postJson($base, [
            'reception_id' => $first['reception']['id'],
            'customer' => ['core_customer_id' => 55, 'name' => 'Cliente con dos equipos', 'phone' => '7000-0055'],
            'core_sucursal_id' => 5, 'core_sucursal_code' => 'M001', 'core_sucursal_name' => 'Casa matriz',
            'device' => ['type' => 'console', 'brand' => 'Sony', 'model' => 'PS5', 'power_status' => 'off'],
            'reported_fault' => 'No enciende',
        ])->assertCreated()->assertJsonPath('data.ticket', 'T-000001')->assertJsonPath('data.reception.sequence', 2)->assertJsonPath('data.reception.equipment_count', 2)->json('data');

        $this->assertNotSame($first['id'], $second['id']);
        $this->getJson("/api/v1/platform/tenants/{$tenant->id}/workshop/receptions/{$first['reception']['id']}")
            ->assertOk()->assertJsonPath('data.ticket', 'T-000001')->assertJsonCount(2, 'data.orders')
            ->assertJsonPath('data.orders.0.device.model', 'A54')->assertJsonPath('data.orders.1.device.model', 'PS5');
        $this->patchJson($base."/{$first['id']}", ['status' => 'diagnosing'])->assertOk();
        $this->assertDatabaseHas('workshop_orders', ['id' => $first['id'], 'status' => 'diagnosing']);
        $this->assertDatabaseHas('workshop_orders', ['id' => $second['id'], 'status' => 'received']);
    }

    public function test_orders_are_isolated_by_tenant(): void
    {
        [$user, $tenant] = $this->member();
        $other = Tenant::query()->create(['slug' => 'other', 'name' => 'Other', 'status' => 'active']);

        $this->actingAs($user)->getJson("/api/v1/platform/tenants/{$other->id}/workshop/orders")->assertForbidden();
        $this->actingAs($user)->getJson("/api/v1/platform/tenants/{$tenant->id}/workshop/orders")->assertOk();
    }

    public function test_facturacion_only_tenant_cannot_use_workshop_api(): void
    {
        $facturacion = PlatformApp::query()->create([
            'key' => 'facturacion',
            'name' => 'Facturación',
            'host' => 'facturacion.stelfaro.com',
            'default_path' => '/',
            'status' => 'active',
        ]);
        $tenant = Tenant::query()->create([
            'slug' => 'billing-only',
            'name' => 'Billing only',
            'status' => 'active',
            'primary_app_id' => $facturacion->id,
        ]);
        $tenant->appAccesses()->create([
            'platform_app_id' => $facturacion->id,
            'status' => 'active',
            'is_default' => true,
        ]);
        $user = User::factory()->create(['email_verified_at' => now(), 'must_change_password' => false]);
        $user->memberships()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
            'status' => 'active',
            'is_default' => true,
        ]);

        $this->actingAs($user)
            ->getJson("/api/v1/platform/tenants/{$tenant->id}/workshop/dashboard")
            ->assertForbidden();
        $this->postJson("/api/v1/platform/tenants/{$tenant->id}/workshop/orders", [])
            ->assertForbidden();
    }

    public function test_viewer_can_consult_workshop_but_cannot_change_it(): void
    {
        [$operator, $tenant] = $this->member();
        $order = $this->actingAs($operator)->postJson("/api/v1/platform/tenants/{$tenant->id}/workshop/orders", [
            'customer' => ['core_customer_id' => 49, 'name' => 'Cliente visible'],
            'device' => ['type' => 'phone', 'brand' => 'Motorola', 'model' => 'G54', 'power_status' => 'on'],
            'reported_fault' => 'No carga',
        ])->assertCreated()->json('data');

        $viewer = User::factory()->create(['email_verified_at' => now(), 'must_change_password' => false]);
        $viewer->memberships()->create([
            'tenant_id' => $tenant->id,
            'role' => 'viewer',
            'status' => 'active',
            'is_default' => true,
        ]);
        $orderUrl = "/api/v1/platform/tenants/{$tenant->id}/workshop/orders/{$order['id']}";

        $this->actingAs($viewer)->getJson("/api/v1/platform/tenants/{$tenant->id}/workshop/dashboard")->assertOk();
        $this->getJson("/api/v1/platform/tenants/{$tenant->id}/workshop/orders")->assertOk();
        $this->getJson($orderUrl)->assertOk();

        $this->postJson("/api/v1/platform/tenants/{$tenant->id}/workshop/orders", [])->assertForbidden();
        $this->patchJson($orderUrl, ['status' => 'diagnosing'])->assertForbidden();
        $this->postJson($orderUrl.'/settlement', [])->assertForbidden();
        $this->postJson($orderUrl.'/payments', [])->assertForbidden();
        $this->postJson($orderUrl.'/invoice-link', [])->assertForbidden();
        $this->postJson($orderUrl.'/photo-session', [])->assertForbidden();

        $this->assertDatabaseHas('workshop_orders', [
            'id' => $order['id'],
            'status' => 'received',
        ]);
        $this->assertDatabaseCount('workshop_order_payments', 0);
    }

    public function test_reception_accepts_an_explicit_zero_advance_without_creating_payment(): void
    {
        [$user, $tenant] = $this->member();

        $response = $this->actingAs($user)->postJson("/api/v1/platform/tenants/{$tenant->id}/workshop/orders", [
            'customer' => ['core_customer_id' => 46, 'name' => 'Cliente contra entrega'],
            'device' => ['type' => 'phone', 'brand' => 'Samsung', 'model' => 'A35', 'power_status' => 'on'],
            'reported_fault' => 'Cambio de pantalla',
            'estimated_total' => 35,
            'advance' => ['amount' => 0, 'method' => 'cash'],
        ]);

        $response->assertCreated()->assertJsonPath('data.paid_total', 0)->assertJsonPath('data.balance', 35);
        $this->assertDatabaseCount('workshop_order_payments', 0);
    }

    public function test_open_order_accepts_later_advances_and_an_optional_payment_on_approval(): void
    {
        [$user, $tenant] = $this->member();
        $order = $this->actingAs($user)->postJson("/api/v1/platform/tenants/{$tenant->id}/workshop/orders", [
            'customer' => ['core_customer_id' => 460, 'name' => 'Cliente con abonos'],
            'device' => ['type' => 'phone', 'brand' => 'Samsung', 'model' => 'A55', 'power_status' => 'on'],
            'reported_fault' => 'Cambio de pantalla',
            'estimated_total' => 100,
        ])->assertCreated()->json('data');
        $url = "/api/v1/platform/tenants/{$tenant->id}/workshop/orders/{$order['id']}";

        $this->postJson($url.'/payments', ['amount' => 25, 'method' => 'cash'])
            ->assertOk()->assertJsonPath('data.paid_total', 25)->assertJsonPath('data.balance', 75);
        $this->assertDatabaseCount('inventory_sales', 0);
        $this->assertDatabaseHas('cash_movements', ['tenant_id' => $tenant->id, 'workshop_order_id' => $order['id'], 'amount' => 25]);

        $this->patchJson($url, ['status' => 'diagnosing'])->assertOk();
        $this->patchJson($url, ['status' => 'awaiting_approval', 'diagnosis' => 'Reemplazar pantalla', 'estimated_total' => 100])->assertOk();
        $this->patchJson($url, [
            'approval_decision' => 'approved',
            'approval_method' => 'in_person',
            'payment' => ['amount' => 15, 'method' => 'transfer', 'reference' => 'TRX-15'],
        ])->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.paid_total', 40)->assertJsonPath('data.balance', 60);
        $this->assertDatabaseHas('workshop_order_payments', ['workshop_order_id' => $order['id'], 'amount' => 15, 'method' => 'transfer']);
    }

    public function test_diagnosis_and_approval_follow_controlled_transitions(): void
    {
        [$user, $tenant] = $this->member();
        $order = $this->actingAs($user)->postJson("/api/v1/platform/tenants/{$tenant->id}/workshop/orders", [
            'customer' => ['core_customer_id' => 45, 'name' => 'Carlos Pérez'],
            'device' => ['type' => 'laptop', 'brand' => 'Dell', 'model' => 'Latitude', 'power_status' => 'off'],
            'reported_fault' => 'No enciende',
        ])->assertCreated()->json('data');

        $url = "/api/v1/platform/tenants/{$tenant->id}/workshop/orders/{$order['id']}";
        $this->patchJson($url, ['status' => 'awaiting_approval'])->assertUnprocessable();
        $this->patchJson($url, ['status' => 'diagnosing'])->assertOk()->assertJsonPath('data.status', 'diagnosing');
        $this->patchJson($url, ['status' => 'awaiting_approval', 'diagnosis' => 'Reemplazar circuito de carga', 'estimated_total' => 55])
            ->assertOk()->assertJsonPath('data.status', 'awaiting_approval');
        $this->patchJson($url, ['approval_decision' => 'approved', 'approval_method' => 'whatsapp', 'approval_notes' => 'Confirmó por mensaje'])
            ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.approval.method', 'whatsapp');
        $this->patchJson($url, ['status' => 'ready'])->assertUnprocessable();
        $this->patchJson($url, ['status' => 'repairing'])->assertOk();
        $this->patchJson($url, ['status' => 'ready'])->assertOk()->assertJsonPath('data.status', 'ready');
        $this->postJson($url.'/settlement', ['action' => 'deliver_close', 'final_total' => 55, 'method' => 'cash', 'document_choice' => 'dte', 'dte_type' => '03'])
            ->assertOk()->assertJsonPath('data.status', 'delivered')->assertJsonPath('data.financial.status', 'settled')->assertJsonPath('data.billing.status', 'pending')->assertJsonPath('data.billing.dte_type', '03')->assertJsonPath('data.balance', 0);
        $this->assertDatabaseHas('workshop_order_payments', ['workshop_order_id' => $order['id'], 'kind' => 'payment', 'amount' => 55]);
        $this->postJson($url.'/invoice-link', ['core_dte_document_id' => 901, 'dte_number' => 'DTE-03-TEST', 'dte_generation_code' => 'UUID-TEST', 'dte_type' => '03'])
            ->assertOk()->assertJsonPath('data.billing.status', 'invoiced')->assertJsonPath('data.billing.core_document_id', 901);
    }

    public function test_cancelled_order_with_advance_requires_and_records_financial_resolution(): void
    {
        [$user, $tenant] = $this->member();
        $order = $this->actingAs($user)->postJson("/api/v1/platform/tenants/{$tenant->id}/workshop/orders", [
            'customer' => ['core_customer_id' => 47, 'name' => 'Cliente con devolución'],
            'device' => ['type' => 'console', 'brand' => 'Sony', 'model' => 'PS5', 'power_status' => 'off'],
            'reported_fault' => 'No enciende', 'estimated_total' => 80,
            'advance' => ['amount' => 30, 'method' => 'cash'],
        ])->assertCreated()->json('data');

        $url = "/api/v1/platform/tenants/{$tenant->id}/workshop/orders/{$order['id']}";
        $this->patchJson($url, ['status' => 'cancelled'])->assertOk()->assertJsonPath('data.financial.status', 'pending');
        $this->postJson($url.'/settlement', ['action' => 'cancel_close', 'retained_amount' => 5, 'method' => 'cash', 'notes' => 'Cliente recibió devolución'])
            ->assertOk()->assertJsonPath('data.financial.status', 'settled')->assertJsonPath('data.financial.final_total', 5)->assertJsonPath('data.refunded_total', 25)->assertJsonPath('data.paid_total', 5);
        $this->assertDatabaseHas('workshop_order_payments', ['workshop_order_id' => $order['id'], 'kind' => 'refund', 'amount' => 25]);
    }

    public function test_approved_or_ready_order_can_be_cancelled_and_charge_diagnosis_without_an_advance(): void
    {
        [$user, $tenant] = $this->member();
        $order = $this->actingAs($user)->postJson("/api/v1/platform/tenants/{$tenant->id}/workshop/orders", [
            'customer' => ['core_customer_id' => 470, 'name' => 'Cliente sin repuesto'],
            'device' => ['type' => 'console', 'brand' => 'Microsoft', 'model' => 'Xbox Series S', 'power_status' => 'off'],
            'reported_fault' => 'No da imagen', 'estimated_total' => 80,
        ])->assertCreated()->json('data');
        $url = "/api/v1/platform/tenants/{$tenant->id}/workshop/orders/{$order['id']}";

        $this->patchJson($url, ['status' => 'diagnosing'])->assertOk();
        $this->patchJson($url, ['status' => 'awaiting_approval', 'diagnosis' => 'Requiere circuito no disponible', 'estimated_total' => 80])->assertOk();
        $this->patchJson($url, ['approval_decision' => 'approved', 'approval_method' => 'call'])->assertOk();
        $this->patchJson($url, ['status' => 'repairing'])->assertOk();
        $this->patchJson($url, ['status' => 'ready'])->assertOk();
        $this->patchJson($url, ['status' => 'cancelled'])->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.financial.closed_at', null);
        $this->postJson($url.'/settlement', ['action' => 'cancel_close', 'diagnostic_charge' => 12, 'method' => 'cash'])
            ->assertUnprocessable()->assertJsonValidationErrors('notes');
        $this->postJson($url.'/settlement', ['action' => 'cancel_close', 'diagnostic_charge' => 12, 'method' => 'cash', 'notes' => 'No se encontró el repuesto'])
            ->assertOk()->assertJsonPath('data.financial.status', 'settled')->assertJsonPath('data.financial.final_total', 12)->assertJsonPath('data.paid_total', 12)->assertJsonPath('data.balance', 0);
        $this->assertDatabaseHas('workshop_order_payments', ['workshop_order_id' => $order['id'], 'kind' => 'payment', 'amount' => 12]);
        $this->assertDatabaseHas('cash_movements', ['tenant_id' => $tenant->id, 'workshop_order_id' => $order['id'], 'direction' => 'in', 'amount' => 12]);
        $this->assertDatabaseHas('inventory_sales', ['tenant_id' => $tenant->id, 'source_type' => 'workshop_order', 'source_id' => (string) $order['id'], 'total_amount' => 12]);
    }

    public function test_ready_order_can_close_as_credit_sale_and_receive_payment_later(): void
    {
        [$user, $tenant] = $this->member();
        $order = $this->actingAs($user)->postJson("/api/v1/platform/tenants/{$tenant->id}/workshop/orders", [
            'customer' => ['core_customer_id' => 48, 'name' => 'Cliente a crédito'],
            'device' => ['type' => 'laptop', 'brand' => 'Lenovo', 'model' => 'T14', 'power_status' => 'on'],
            'reported_fault' => 'Cambio de teclado', 'estimated_total' => 60,
        ])->assertCreated()->json('data');
        $url = "/api/v1/platform/tenants/{$tenant->id}/workshop/orders/{$order['id']}";
        $this->patchJson($url, ['status' => 'diagnosing'])->assertOk();
        $this->patchJson($url, ['status' => 'awaiting_approval', 'diagnosis' => 'Teclado dañado', 'estimated_total' => 60])->assertOk();
        $this->patchJson($url, ['approval_decision' => 'approved', 'approval_method' => 'call'])->assertOk();
        $this->patchJson($url, ['status' => 'repairing'])->assertOk();
        $this->patchJson($url, ['status' => 'ready'])->assertOk();
        $this->postJson($url.'/settlement', ['action' => 'deliver_close', 'final_total' => 60, 'amount_received' => 20, 'method' => 'cash', 'document_choice' => 'work_order'])
            ->assertOk()->assertJsonPath('data.status', 'delivered')->assertJsonPath('data.financial.status', 'pending')->assertJsonPath('data.paid_total', 20)->assertJsonPath('data.balance', 40);
        $this->assertDatabaseHas('inventory_sales', ['tenant_id' => $tenant->id, 'source_type' => 'workshop_order', 'source_id' => (string) $order['id']]);
        $this->getJson("/api/v1/platform/tenants/{$tenant->id}/workshop/orders?payment_status=receivable")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $order['id']);
        $this->getJson("/api/v1/platform/tenants/{$tenant->id}/cash/sales-report?payment_status=receivable")
            ->assertOk()->assertJsonPath('summary.receivable', 40)->assertJsonPath('data.0.outstanding_amount', 40);
        $this->postJson($url.'/payments', ['amount' => 40, 'method' => 'transfer'])->assertOk()->assertJsonPath('data.balance', 0)->assertJsonPath('data.financial.status', 'settled');
        $this->getJson("/api/v1/platform/tenants/{$tenant->id}/workshop/orders?payment_status=receivable")
            ->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('inventory_sales', 1);
    }

    public function test_dashboard_returns_operational_workshop_summary(): void
    {
        [$user, $tenant] = $this->member();
        $this->actingAs($user)->postJson("/api/v1/platform/tenants/{$tenant->id}/workshop/orders", [
            'customer' => ['core_customer_id' => 91, 'name' => 'Cliente dashboard'],
            'device' => ['type' => 'phone', 'brand' => 'Samsung', 'model' => 'A54', 'power_status' => 'on'],
            'reported_fault' => 'No carga',
        ])->assertCreated();

        $this->getJson("/api/v1/platform/tenants/{$tenant->id}/workshop/dashboard")
            ->assertOk()
            ->assertJsonPath('orders.active', 1)
            ->assertJsonPath('orders.received_today', 1)
            ->assertJsonCount(1, 'recent_orders')
            ->assertJsonStructure(['commercial' => ['sales_today', 'sales_month', 'receivables']]);
    }

    public function test_dashboard_subtracts_credit_notes_separates_iva_and_ignores_fse(): void
    {
        [$user, $tenant] = $this->member();
        $base = "/api/v1/platform/tenants/{$tenant->id}/inventory/sales";
        $this->actingAs($user)->postJson($base, [
            'source_id' => 'FE-1', 'sale_date' => today()->toDateString(), 'fiscal_document_type' => '01',
            'net_amount' => 100, 'tax_amount' => 13, 'total_amount' => 113,
            'metadata' => ['payment_status' => 'receivable'],
            'lines' => [['description' => 'Venta', 'quantity' => 1, 'net_total' => 100, 'tax_amount' => 13, 'total_amount' => 113]],
        ])->assertCreated();
        $this->postJson($base, [
            'source_id' => 'NC-1', 'sale_date' => today()->toDateString(), 'fiscal_document_type' => '05',
            'net_amount' => 20, 'tax_amount' => 2.60, 'total_amount' => 22.60,
            'lines' => [['description' => 'Devolución', 'quantity' => 1, 'net_total' => 20, 'tax_amount' => 2.60, 'total_amount' => 22.60]],
        ])->assertCreated();
        $this->postJson($base, [
            'source_id' => 'FSE-1', 'sale_date' => today()->toDateString(), 'fiscal_document_type' => '14',
            'net_amount' => 50, 'tax_amount' => 0, 'total_amount' => 50,
            'lines' => [['description' => 'Compra a sujeto excluido', 'quantity' => 1, 'net_total' => 50, 'total_amount' => 50]],
        ])->assertCreated();

        $this->getJson("/api/v1/platform/tenants/{$tenant->id}/workshop/dashboard")
            ->assertOk()
            ->assertJsonPath('commercial.sales_net_today', 80)
            ->assertJsonPath('commercial.sales_tax_today', 10.4)
            ->assertJsonPath('commercial.sales_today', 90.4)
            ->assertJsonPath('commercial.receivables', 113)
            ->assertJsonPath('commercial.sales_net_month', 80)
            ->assertJsonPath('commercial.sales_tax_month', 10.4)
            ->assertJsonPath('commercial.sales_month', 90.4);
        $this->getJson("/api/v1/platform/tenants/{$tenant->id}/cash/sales-report?payment_status=receivable")
            ->assertOk()->assertJsonPath('summary.receivable', 113)->assertJsonPath('data.0.outstanding_amount', 113);
    }

    private function member(): array
    {
        $tenant = Tenant::query()->create(['slug' => 'workshop', 'name' => 'Workshop', 'status' => 'active']);
        $taller = PlatformApp::query()->firstOrCreate(
            ['key' => 'taller'],
            ['name' => 'Taller electrónico', 'host' => 'taller.stelfaro.com', 'default_path' => '/', 'status' => 'active'],
        );
        $tenant->appAccesses()->create([
            'platform_app_id' => $taller->id,
            'status' => 'active',
            'is_default' => true,
        ]);
        $facturacion = PlatformApp::query()->firstOrCreate(
            ['key' => 'facturacion'],
            ['name' => 'Facturación', 'host' => 'facturacion.stelfaro.com', 'default_path' => '/', 'status' => 'active'],
        );
        $tenant->appAccesses()->create([
            'platform_app_id' => $facturacion->id,
            'status' => 'active',
            'is_default' => false,
        ]);
        $user = User::factory()->create(['email_verified_at' => now(), 'must_change_password' => false]);
        $user->memberships()->create(['tenant_id' => $tenant->id, 'role' => 'company_admin', 'status' => 'active', 'is_default' => true]);
        $register = CashRegister::query()->create(['tenant_id' => $tenant->id, 'name' => 'Caja principal', 'status' => 'active']);
        CashSession::query()->create(['tenant_id' => $tenant->id, 'cash_register_id' => $register->id, 'opened_by' => $user->id, 'opening_balance' => 0, 'status' => 'open', 'opened_at' => now()]);

        return [$user, $tenant];
    }
}

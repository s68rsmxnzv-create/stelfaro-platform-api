<?php

namespace Tests\Feature;

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

    public function test_orders_are_isolated_by_tenant(): void
    {
        [$user, $tenant] = $this->member();
        $other = Tenant::query()->create(['slug' => 'other', 'name' => 'Other', 'status' => 'active']);

        $this->actingAs($user)->getJson("/api/v1/platform/tenants/{$other->id}/workshop/orders")->assertForbidden();
        $this->actingAs($user)->getJson("/api/v1/platform/tenants/{$tenant->id}/workshop/orders")->assertOk();
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
        $this->postJson($url.'/settlement', ['action' => 'deliver_close', 'final_total' => 55, 'method' => 'cash'])
            ->assertOk()->assertJsonPath('data.status', 'delivered')->assertJsonPath('data.financial.status', 'settled')->assertJsonPath('data.balance', 0);
        $this->assertDatabaseHas('workshop_order_payments', ['workshop_order_id' => $order['id'], 'kind' => 'payment', 'amount' => 55]);
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

    private function member(): array
    {
        $tenant = Tenant::query()->create(['slug' => 'workshop', 'name' => 'Workshop', 'status' => 'active']);
        $user = User::factory()->create(['email_verified_at' => now(), 'must_change_password' => false]);
        $user->memberships()->create(['tenant_id' => $tenant->id, 'role' => 'company_admin', 'status' => 'active', 'is_default' => true]);

        return [$user, $tenant];
    }
}

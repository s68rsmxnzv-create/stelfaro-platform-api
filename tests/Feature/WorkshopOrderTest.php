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
    }

    public function test_orders_are_isolated_by_tenant(): void
    {
        [$user, $tenant] = $this->member();
        $other = Tenant::query()->create(['slug' => 'other', 'name' => 'Other', 'status' => 'active']);

        $this->actingAs($user)->getJson("/api/v1/platform/tenants/{$other->id}/workshop/orders")->assertForbidden();
        $this->actingAs($user)->getJson("/api/v1/platform/tenants/{$tenant->id}/workshop/orders")->assertOk();
    }

    private function member(): array
    {
        $tenant = Tenant::query()->create(['slug' => 'workshop', 'name' => 'Workshop', 'status' => 'active']);
        $user = User::factory()->create(['email_verified_at' => now(), 'must_change_password' => false]);
        $user->memberships()->create(['tenant_id' => $tenant->id, 'role' => 'company_admin', 'status' => 'active', 'is_default' => true]);

        return [$user, $tenant];
    }
}

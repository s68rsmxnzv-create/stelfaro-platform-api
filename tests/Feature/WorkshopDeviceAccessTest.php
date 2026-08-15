<?php

namespace Tests\Feature;

use App\Models\PlatformApp;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkshopCustomer;
use App\Models\WorkshopDevice;
use App\Models\WorkshopOrder;
use App\Services\WorkshopDeviceAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkshopDeviceAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_pin_creates_temporary_session_that_can_reveal_secret_and_advance_status(): void
    {
        [$user, $tenant, $order] = $this->order();
        $credentials = app(WorkshopDeviceAccessService::class)->ensure($order);
        $token = basename($credentials['url']);

        $this->get("https://new.stelfaro.com/taller/equipo/{$token}")
            ->assertOk()
            ->assertSee('T-000001')
            ->assertDontSee('2580');

        $this->postJson("/api/v1/workshop/device-access/{$token}/unlock", ['pin' => '000000'])
            ->assertUnprocessable();
        $unlocked = $this->postJson("/api/v1/workshop/device-access/{$token}/unlock", ['pin' => $credentials['pin']])
            ->assertOk()
            ->assertJsonPath('order.status', 'received')
            ->json();
        $headers = ['X-Workshop-Session' => $unlocked['session_token']];

        $this->getJson("/api/v1/workshop/device-access/{$token}/secret", $headers)
            ->assertOk()
            ->assertJsonPath('secret', '2580')
            ->assertJsonPath('type', 'code');
        $this->patchJson("/api/v1/workshop/device-access/{$token}/status", ['status' => 'diagnosing'], $headers)
            ->assertOk()
            ->assertJsonPath('order.status', 'diagnosing');
        $this->assertDatabaseHas('workshop_device_access_events', ['workshop_order_id' => $order->id, 'action' => 'secret_revealed']);
    }

    public function test_workshop_ticket_settings_are_tenant_scoped(): void
    {
        [$user, $tenant] = $this->order();
        $this->actingAs($user)->patchJson("/api/v1/platform/tenants/{$tenant->id}/workshop/ticket-settings", [
            'receipt_copies' => 1,
            'terms' => "Primera condición.\n\nSegunda condición.",
        ])->assertOk()->assertJsonPath('data.receipt_copies', 1);
        $this->getJson("/api/v1/platform/tenants/{$tenant->id}/workshop/ticket-settings")
            ->assertOk()
            ->assertJsonPath('data.terms', "Primera condición.\n\nSegunda condición.");
    }

    private function order(): array
    {
        $tenant = Tenant::query()->create(['slug' => 'device-access', 'name' => 'Device Access', 'status' => 'active']);
        $taller = PlatformApp::query()->firstOrCreate(['key' => 'taller'], ['name' => 'Taller', 'host' => 'new.stelfaro.com', 'default_path' => '/', 'status' => 'active']);
        $tenant->appAccesses()->create(['platform_app_id' => $taller->id, 'status' => 'active', 'is_default' => true]);
        $user = User::factory()->create(['email_verified_at' => now(), 'must_change_password' => false]);
        $user->memberships()->create(['tenant_id' => $tenant->id, 'role' => 'company_admin', 'status' => 'active', 'is_default' => true]);
        $customer = WorkshopCustomer::query()->create(['tenant_id' => $tenant->id, 'core_customer_id' => 1, 'name' => 'Cliente']);
        $device = WorkshopDevice::query()->create(['tenant_id' => $tenant->id, 'workshop_customer_id' => $customer->id, 'type' => 'phone', 'brand' => 'Demo', 'model' => 'Uno', 'power_status' => 'on', 'is_locked' => true, 'access_type' => 'code', 'access_secret' => '2580']);
        $order = WorkshopOrder::query()->create(['tenant_id' => $tenant->id, 'workshop_device_id' => $device->id, 'received_by' => $user->id, 'ticket_number' => 1, 'status' => 'received', 'priority' => 'normal', 'reported_fault' => 'No carga', 'received_at' => now()]);

        return [$user, $tenant, $order];
    }
}

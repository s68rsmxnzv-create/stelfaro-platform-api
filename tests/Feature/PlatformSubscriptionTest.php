<?php

namespace Tests\Feature;

use App\Models\PlatformApp;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_owner_can_list_subscription_plans_and_tenants(): void
    {
        $owner = User::factory()->create(['platform_role' => 'platform_owner']);
        Tenant::query()->create([
            'slug' => 'cliente-demo',
            'name' => 'Cliente Demo',
        ]);

        $this->actingAs($owner)
            ->getJson('/api/v1/admin/platform/subscriptions')
            ->assertOk()
            ->assertJsonPath('plans.0.key', 'implementation')
            ->assertJsonPath('subscriptions.0.tenant.name', 'Cliente Demo')
            ->assertJsonPath('subscriptions.0.subscription', null);
    }

    public function test_company_owner_cannot_list_platform_subscriptions(): void
    {
        $tenant = Tenant::query()->create([
            'slug' => 'cliente-demo',
            'name' => 'Cliente Demo',
        ]);
        $user = User::factory()->create();
        $user->memberships()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
            'status' => 'active',
            'is_default' => true,
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/admin/platform/subscriptions')
            ->assertForbidden();
    }

    public function test_platform_owner_can_assign_subscription_and_sync_app_access(): void
    {
        $owner = User::factory()->create(['platform_role' => 'platform_owner']);
        $tenant = Tenant::query()->create([
            'slug' => 'cliente-demo',
            'name' => 'Cliente Demo',
        ]);
        $facturacion = PlatformApp::query()->create([
            'key' => 'facturacion',
            'name' => 'Facturacion',
        ]);
        $taller = PlatformApp::query()->create([
            'key' => 'taller',
            'name' => 'Taller',
        ]);
        $extra = PlatformApp::query()->create([
            'key' => 'crm',
            'name' => 'CRM',
        ]);
        $tenant->appAccesses()->create([
            'platform_app_id' => $extra->id,
            'status' => 'active',
            'is_default' => true,
        ]);
        $plan = SubscriptionPlan::query()->where('key', 'pro')->firstOrFail();

        $this->actingAs($owner)
            ->putJson("/api/v1/admin/platform/tenants/{$tenant->id}/subscription", [
                'plan_id' => $plan->id,
                'status' => 'active',
                'billing_cycle' => 'monthly',
                'current_period_ends_at' => now()->addMonth()->toISOString(),
            ])
            ->assertOk()
            ->assertJsonPath('subscription.tenant_id', $tenant->id)
            ->assertJsonPath('subscription.plan.key', 'pro')
            ->assertJsonPath('subscription.status', 'active');

        $this->assertDatabaseHas('tenant_subscriptions', [
            'tenant_id' => $tenant->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('tenant_app_accesses', [
            'tenant_id' => $tenant->id,
            'platform_app_id' => $facturacion->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('tenant_app_accesses', [
            'tenant_id' => $tenant->id,
            'platform_app_id' => $taller->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('tenant_app_accesses', [
            'tenant_id' => $tenant->id,
            'platform_app_id' => $extra->id,
            'status' => 'inactive',
            'is_default' => false,
        ]);
    }

    public function test_suspended_subscription_suspends_included_app_access(): void
    {
        $owner = User::factory()->create(['platform_role' => 'platform_owner']);
        $tenant = Tenant::query()->create([
            'slug' => 'cliente-demo',
            'name' => 'Cliente Demo',
        ]);
        $facturacion = PlatformApp::query()->create([
            'key' => 'facturacion',
            'name' => 'Facturacion',
        ]);
        $plan = SubscriptionPlan::query()->where('key', 'starter')->firstOrFail();

        $this->actingAs($owner)
            ->putJson("/api/v1/admin/platform/tenants/{$tenant->id}/subscription", [
                'plan_id' => $plan->id,
                'status' => 'suspended',
            ])
            ->assertOk()
            ->assertJsonPath('subscription.status', 'suspended');

        $this->assertDatabaseHas('tenant_app_accesses', [
            'tenant_id' => $tenant->id,
            'platform_app_id' => $facturacion->id,
            'status' => 'suspended',
        ]);
    }

    public function test_platform_owner_can_assign_subscription_by_core_company_for_custom_days(): void
    {
        $owner = User::factory()->create(['platform_role' => 'platform_owner']);
        $tenant = Tenant::query()->create([
            'slug' => 'cliente-demo',
            'name' => 'Cliente Demo',
            'metadata' => ['core_empresa_id' => 123],
        ]);
        PlatformApp::query()->create([
            'key' => 'facturacion',
            'name' => 'Facturacion',
        ]);
        $plan = SubscriptionPlan::query()->where('key', 'starter')->firstOrFail();

        $response = $this->actingAs($owner)
            ->putJson('/api/v1/admin/platform/tenants/by-core-empresa/123/subscription', [
                'plan_id' => $plan->id,
                'status' => 'active',
                'billing_cycle' => 'manual',
                'duration_days' => 90,
            ])
            ->assertOk()
            ->assertJsonPath('subscription.tenant_id', $tenant->id)
            ->assertJsonPath('subscription.status', 'active');

        $endsAt = \Carbon\CarbonImmutable::parse((string) $response->json('subscription.current_period_ends_at'));

        $this->assertTrue($endsAt->between(now()->addDays(89), now()->addDays(91)));
        $this->assertDatabaseHas('tenant_subscriptions', [
            'tenant_id' => $tenant->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'manual',
        ]);
    }
}

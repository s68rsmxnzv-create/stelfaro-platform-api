<?php

namespace Tests\Feature;

use App\Models\PlatformAuditLog;
use App\Models\SecurityEvent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformAuditLogAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_owner_can_list_platform_and_security_audit_logs(): void
    {
        $owner = User::factory()->create(['platform_role' => 'platform_owner']);
        $tenant = Tenant::query()->create(['slug' => 'demo', 'name' => 'Demo']);

        PlatformAuditLog::query()->create([
            'user_id' => $owner->id,
            'tenant_id' => $tenant->id,
            'action' => 'catalog.item.create',
            'result' => 'success',
            'status_code' => 201,
        ]);

        SecurityEvent::query()->create([
            'type' => 'auth.login_failed',
            'severity' => 'warning',
            'field' => 'email',
        ]);

        $response = $this->actingAs($owner)
            ->getJson('/api/v1/admin/platform/audit-logs?source=all');

        $response
            ->assertOk()
            ->assertJsonPath('meta.source', 'all')
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['source' => 'platform', 'action' => 'catalog.item.create'])
            ->assertJsonFragment(['source' => 'security', 'action' => 'auth.login_failed']);
    }

    public function test_non_platform_admin_cannot_list_audit_logs(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/v1/admin/platform/audit-logs')
            ->assertForbidden();
    }

    public function test_company_admin_can_list_only_tenant_audit_logs(): void
    {
        $tenant = Tenant::query()->create(['slug' => 'tenant-audit', 'name' => 'Tenant Audit']);
        $otherTenant = Tenant::query()->create(['slug' => 'tenant-other', 'name' => 'Tenant Other']);
        $admin = User::factory()->create();
        $admin->memberships()->create([
            'tenant_id' => $tenant->id,
            'role' => 'company_admin',
            'status' => 'active',
            'is_default' => true,
        ]);

        PlatformAuditLog::query()->create([
            'user_id' => $admin->id,
            'tenant_id' => $tenant->id,
            'action' => 'tenant.user.invite',
            'result' => 'success',
            'status_code' => 201,
        ]);
        PlatformAuditLog::query()->create([
            'user_id' => $admin->id,
            'tenant_id' => $otherTenant->id,
            'action' => 'other.tenant.action',
            'result' => 'success',
            'status_code' => 201,
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/platform/tenants/{$tenant->id}/audit-logs");

        $response
            ->assertOk()
            ->assertJsonPath('meta.tenant_id', $tenant->id)
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['source' => 'platform', 'action' => 'tenant.user.invite'])
            ->assertJsonMissing(['action' => 'other.tenant.action']);
    }

    public function test_billing_user_cannot_list_tenant_audit_logs(): void
    {
        $tenant = Tenant::query()->create(['slug' => 'tenant-billing-user', 'name' => 'Tenant Billing User']);
        $user = User::factory()->create();
        $user->memberships()->create([
            'tenant_id' => $tenant->id,
            'role' => 'billing_user',
            'status' => 'active',
            'is_default' => true,
        ]);

        $this->actingAs($user)
            ->getJson("/api/v1/platform/tenants/{$tenant->id}/audit-logs")
            ->assertForbidden();
    }

    public function test_billing_admin_can_list_tenant_audit_logs(): void
    {
        $tenant = Tenant::query()->create(['slug' => 'tenant-billing-admin', 'name' => 'Tenant Billing Admin']);
        $user = User::factory()->create();
        $user->memberships()->create([
            'tenant_id' => $tenant->id,
            'role' => 'billing_admin',
            'status' => 'active',
            'is_default' => true,
        ]);

        PlatformAuditLog::query()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'action' => 'billing.admin.action',
            'result' => 'success',
            'status_code' => 201,
        ]);

        $this->actingAs($user)
            ->getJson("/api/v1/platform/tenants/{$tenant->id}/audit-logs")
            ->assertOk()
            ->assertJsonFragment(['action' => 'billing.admin.action']);
    }
}

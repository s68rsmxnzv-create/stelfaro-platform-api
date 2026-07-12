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
}

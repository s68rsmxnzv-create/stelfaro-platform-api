<?php

namespace Tests\Feature;

use App\Models\PlatformAuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformAuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_mutating_platform_request_is_audited_without_sensitive_payload(): void
    {
        [$owner, $tenant] = $this->userWithTenantRole('owner');

        $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/catalog/categories", [
                'name' => 'Repuestos',
                'kind' => 'product',
                'password' => 'no-debe-guardarse',
            ])
            ->assertCreated();

        $log = PlatformAuditLog::query()->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame($owner->id, $log->user_id);
        $this->assertSame($tenant->id, $log->tenant_id);
        $this->assertSame('success', $log->result);
        $this->assertSame(201, $log->status_code);
        $this->assertSame('post.api.v1.platform.tenants.'.$tenant->id.'.catalog.categories', $log->action);
        $this->assertContains('name', $log->metadata['input_keys']);
        $this->assertNotContains('password', $log->metadata['input_keys']);
    }

    public function test_read_requests_are_not_audited_as_activity_changes(): void
    {
        [$owner, $tenant] = $this->userWithTenantRole('owner');

        $this->actingAs($owner)
            ->getJson("/api/v1/platform/tenants/{$tenant->id}/catalog/categories")
            ->assertOk();

        $this->assertDatabaseCount('platform_audit_logs', 0);
    }

    /**
     * @return array{0: User, 1: Tenant}
     */
    private function userWithTenantRole(string $role, ?Tenant $tenant = null): array
    {
        $tenant ??= Tenant::query()->create([
            'slug' => 'tenant-'.strtolower($role).'-'.Str::random(6),
            'name' => 'Tenant '.$role,
        ]);

        $user = User::factory()->create();
        $user->memberships()->create([
            'tenant_id' => $tenant->id,
            'role' => $role,
            'status' => 'active',
            'is_default' => true,
        ]);

        return [$user, $tenant];
    }
}

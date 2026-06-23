<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlatformTenantLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_owner_can_resolve_tenant_by_core_empresa_id(): void
    {
        $owner = User::factory()->create(['platform_role' => 'platform_owner']);
        $tenant = Tenant::query()->create([
            'slug' => 'servicio-tecnico-el-faro',
            'name' => 'Servicio Tecnico El Faro',
            'metadata' => ['core_empresa_id' => 123],
        ]);

        $this->actingAs($owner)
            ->getJson('/api/v1/admin/platform/tenants/by-core-empresa/123')
            ->assertOk()
            ->assertJsonPath('tenant.id', $tenant->id)
            ->assertJsonPath('tenant.core_empresa_id', 123);
    }

    public function test_company_owner_can_resolve_only_their_tenant_by_core_empresa_id(): void
    {
        $ownedTenant = Tenant::query()->create([
            'slug' => 'empresa-propia',
            'name' => 'Empresa Propia',
            'metadata' => ['core_empresa_id' => 123],
        ]);
        $otherTenant = Tenant::query()->create([
            'slug' => 'empresa-ajena',
            'name' => 'Empresa Ajena',
            'metadata' => ['core_empresa_id' => 456],
        ]);
        $user = User::factory()->create();
        $user->memberships()->create([
            'tenant_id' => $ownedTenant->id,
            'role' => 'owner',
            'status' => 'active',
            'is_default' => true,
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/admin/platform/tenants/by-core-empresa/123')
            ->assertOk()
            ->assertJsonPath('tenant.id', $ownedTenant->id);

        $this->actingAs($user)
            ->getJson('/api/v1/admin/platform/tenants/by-core-empresa/456')
            ->assertForbidden();

        $this->assertSame(456, $otherTenant->metadata['core_empresa_id']);
    }

    public function test_missing_core_empresa_returns_not_found(): void
    {
        $owner = User::factory()->create(['platform_role' => 'platform_owner']);

        $this->actingAs($owner)
            ->getJson('/api/v1/admin/platform/tenants/by-core-empresa/999')
            ->assertNotFound()
            ->assertJsonPath('tenant', null);
    }

    public function test_platform_owner_can_purge_tenant_by_core_empresa_id(): void
    {
        $owner = User::factory()->create(['platform_role' => 'platform_owner']);
        $tenantOnlyUser = User::factory()->create(['platform_role' => null]);
        $globalUser = User::factory()->create(['platform_role' => 'platform_owner']);
        $tenant = Tenant::query()->create([
            'slug' => 'empresa-temporal',
            'name' => 'Empresa Temporal',
            'metadata' => ['core_empresa_id' => 123],
        ]);
        $appId = DB::table('platform_apps')->insertGetId([
            'key' => 'billing',
            'name' => 'Facturacion',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tenant_app_accesses')->insert([
            'tenant_id' => $tenant->id,
            'platform_app_id' => $appId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $membershipId = DB::table('user_tenant_memberships')->insertGetId([
            'tenant_id' => $tenant->id,
            'user_id' => $tenantOnlyUser->id,
            'role' => 'billing_user',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_tenant_memberships')->insert([
            'tenant_id' => $tenant->id,
            'user_id' => $globalUser->id,
            'role' => 'owner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_fiscal_assignments')->insert([
            'membership_id' => $membershipId,
            'core_empresa_id' => 123,
            'core_sucursal_id' => 456,
            'core_punto_venta_id' => 789,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_invitations')->insert([
            'tenant_id' => $tenant->id,
            'email' => 'pendiente@example.test',
            'role' => 'billing_user',
            'token_hash' => hash('sha256', 'token'),
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($owner)
            ->deleteJson('/api/v1/admin/platform/tenants/by-core-empresa/123')
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->assertDatabaseMissing('tenants', ['id' => $tenant->id]);
        $this->assertDatabaseMissing('tenant_app_accesses', ['tenant_id' => $tenant->id]);
        $this->assertDatabaseMissing('user_tenant_memberships', ['tenant_id' => $tenant->id]);
        $this->assertDatabaseMissing('user_invitations', ['tenant_id' => $tenant->id]);
        $this->assertDatabaseMissing('user_fiscal_assignments', ['membership_id' => $membershipId]);
        $this->assertDatabaseMissing('users', ['id' => $tenantOnlyUser->id]);
        $this->assertDatabaseHas('users', ['id' => $globalUser->id]);
        $this->assertDatabaseHas('users', ['id' => $owner->id]);
    }

    public function test_company_owner_cannot_purge_tenant_by_core_empresa_id(): void
    {
        $tenant = Tenant::query()->create([
            'slug' => 'empresa-propia',
            'name' => 'Empresa Propia',
            'metadata' => ['core_empresa_id' => 123],
        ]);
        $user = User::factory()->create();
        $user->memberships()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
            'status' => 'active',
            'is_default' => true,
        ]);

        $this->actingAs($user)
            ->deleteJson('/api/v1/admin/platform/tenants/by-core-empresa/123')
            ->assertForbidden();

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id]);
    }

    public function test_platform_owner_can_purge_tenant_by_core_empresa_id_with_post_action(): void
    {
        $owner = User::factory()->create(['platform_role' => 'platform_owner']);
        $tenant = Tenant::query()->create([
            'slug' => 'empresa-post',
            'name' => 'Empresa Post',
            'metadata' => ['core_empresa_id' => 321],
        ]);

        $this->actingAs($owner)
            ->postJson('/api/v1/admin/platform/tenants/by-core-empresa/321/purge')
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->assertDatabaseMissing('tenants', ['id' => $tenant->id]);
    }
}

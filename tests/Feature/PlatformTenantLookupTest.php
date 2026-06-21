<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}

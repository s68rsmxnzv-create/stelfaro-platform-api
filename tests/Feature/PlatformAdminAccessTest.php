<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\PlatformAdminAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformAdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_owner_role_can_access_platform_admin(): void
    {
        $user = User::factory()->create(['platform_role' => 'platform_owner']);

        $this->assertTrue(app(PlatformAdminAccess::class)->allows($user));
    }

    public function test_company_owner_membership_cannot_access_platform_admin(): void
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

        $this->assertFalse(app(PlatformAdminAccess::class)->allows($user));
    }

    public function test_platform_owner_role_can_access_fiscal_admin_scope(): void
    {
        $user = User::factory()->create(['platform_role' => 'platform_owner']);

        $this->assertTrue(app(PlatformAdminAccess::class)->allows($user, 'fiscal'));
    }
}

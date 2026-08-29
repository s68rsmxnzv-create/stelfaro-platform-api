<?php

namespace Tests\Feature;

use App\Models\PlatformApp;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PlatformAccessPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformAccessPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_owner_can_manage_global_users(): void
    {
        $policy = app(PlatformAccessPolicy::class);
        $user = User::factory()->create(['platform_role' => 'platform_owner']);

        $this->assertTrue($policy->canViewGlobalUsers($user));
        $this->assertTrue($policy->canCreateGlobalUsers($user));
    }

    public function test_platform_owner_bootstrap_email_can_manage_global_users(): void
    {
        config(['platform.admin.platform_emails' => ['owner@example.test']]);

        $policy = app(PlatformAccessPolicy::class);
        $user = User::factory()->create(['email' => 'owner@example.test']);

        $this->assertTrue($policy->canViewGlobalUsers($user));
        $this->assertTrue($policy->canCreateGlobalUsers($user));
    }

    public function test_company_admin_cannot_manage_global_users(): void
    {
        $policy = app(PlatformAccessPolicy::class);
        $user = $this->userWithMembership('company_admin');

        $this->assertFalse($policy->canViewGlobalUsers($user));
        $this->assertFalse($policy->canCreateGlobalUsers($user));
    }

    public function test_company_owner_cannot_manage_global_users_without_platform_role(): void
    {
        $policy = app(PlatformAccessPolicy::class);
        $user = $this->userWithMembership('owner');

        $this->assertFalse($policy->canViewGlobalUsers($user));
        $this->assertFalse($policy->canCreateGlobalUsers($user));
    }

    public function test_platform_admin_is_reserved_and_cannot_manage_global_users_yet(): void
    {
        $policy = app(PlatformAccessPolicy::class);
        $user = User::factory()->create(['platform_role' => 'platform_admin']);

        $this->assertFalse($policy->canViewGlobalUsers($user));
        $this->assertFalse($policy->canCreateGlobalUsers($user));
    }

    public function test_company_owner_can_invite_users_only_to_their_company(): void
    {
        $policy = app(PlatformAccessPolicy::class);
        $ownedTenant = Tenant::query()->create([
            'slug' => 'owned-company',
            'name' => 'Owned Company',
        ]);
        $otherTenant = Tenant::query()->create([
            'slug' => 'other-company',
            'name' => 'Other Company',
        ]);
        $user = User::factory()->create();
        $user->memberships()->create([
            'tenant_id' => $ownedTenant->id,
            'role' => 'owner',
            'status' => 'active',
            'is_default' => true,
        ]);

        $this->assertTrue($policy->canInviteTenantUsers($user, $ownedTenant));
        $this->assertFalse($policy->canInviteTenantUsers($user, $otherTenant));
    }

    public function test_billing_user_and_viewer_cannot_administer_company_users(): void
    {
        $policy = app(PlatformAccessPolicy::class);
        [$billingUser, $tenant] = $this->userWithTenantRole('billing_user');
        [$viewer] = $this->userWithTenantRole('viewer', $tenant);

        $this->assertFalse($policy->canInviteTenantUsers($billingUser, $tenant));
        $this->assertFalse($policy->canChangeTenantMemberRole($billingUser, $tenant));
        $this->assertFalse($policy->canSuspendTenantMember($viewer, $tenant));
        $this->assertFalse($policy->canRemoveTenantAccess($viewer, $tenant));
    }

    public function test_operational_permissions_allow_billing_roles_but_exclude_viewers_and_suspended_users(): void
    {
        $policy = app(PlatformAccessPolicy::class);
        [$owner, $tenant] = $this->userWithTenantRole('owner');
        [$companyAdmin] = $this->userWithTenantRole('company_admin', $tenant);
        [$billingAdmin] = $this->userWithTenantRole('billing_admin', $tenant);
        [$billingUser] = $this->userWithTenantRole('billing_user', $tenant);
        [$seller] = $this->userWithTenantRole('seller', $tenant);
        [$viewer] = $this->userWithTenantRole('viewer', $tenant);
        [$accountant] = $this->userWithTenantRole('accountant', $tenant);

        $this->assertTrue($policy->canOperateTenant($owner, $tenant));
        $this->assertTrue($policy->canOperateTenant($companyAdmin, $tenant));
        $this->assertTrue($policy->canOperateTenant($billingAdmin, $tenant));
        $this->assertTrue($policy->canOperateTenant($billingUser, $tenant));
        $this->assertTrue($policy->canOperateTenant($seller, $tenant));
        $this->assertFalse($policy->canOperateTenant($viewer, $tenant));
        $this->assertFalse($policy->canOperateTenant($accountant, $tenant));

        $billingUser->memberships()
            ->where('tenant_id', $tenant->id)
            ->update(['status' => 'suspended']);

        $this->assertFalse($policy->canOperateTenant($billingUser, $tenant));
    }

    public function test_seller_and_accountant_cannot_administer_company_users(): void
    {
        $policy = app(PlatformAccessPolicy::class);
        [$seller, $tenant] = $this->userWithTenantRole('seller');
        [$accountant] = $this->userWithTenantRole('accountant', $tenant);

        $this->assertFalse($policy->canInviteTenantUsers($seller, $tenant));
        $this->assertFalse($policy->canChangeTenantMemberRole($seller, $tenant));
        $this->assertFalse($policy->canInviteTenantUsers($accountant, $tenant));
        $this->assertFalse($policy->canChangeTenantMemberRole($accountant, $tenant));
    }

    public function test_tenant_app_access_requires_active_membership_and_active_assignment(): void
    {
        $policy = app(PlatformAccessPolicy::class);
        [$user, $tenant] = $this->userWithTenantRole('owner');
        $facturacion = PlatformApp::query()->create([
            'key' => 'facturacion',
            'name' => 'Facturación',
            'status' => 'active',
        ]);
        $taller = PlatformApp::query()->create([
            'key' => 'taller',
            'name' => 'Taller electrónico',
            'status' => 'active',
        ]);
        $tenant->appAccesses()->create([
            'platform_app_id' => $facturacion->id,
            'status' => 'active',
        ]);
        $tenant->appAccesses()->create([
            'platform_app_id' => $taller->id,
            'status' => 'inactive',
        ]);

        $this->assertTrue($policy->canAccessTenantApp($user, $tenant, 'facturacion'));
        $this->assertFalse($policy->canAccessTenantApp($user, $tenant, 'taller'));

        $user->memberships()->where('tenant_id', $tenant->id)->update(['status' => 'suspended']);

        $this->assertFalse($policy->canAccessTenantApp($user, $tenant, 'facturacion'));
    }

    public function test_user_can_change_active_tenant_only_with_active_membership(): void
    {
        $policy = app(PlatformAccessPolicy::class);
        [$user, $tenant] = $this->userWithTenantRole('billing_user');
        $activeMembership = $user->memberships()->where('tenant_id', $tenant->id)->firstOrFail();
        $suspendedMembership = $user->memberships()->create([
            'tenant_id' => Tenant::query()->create([
                'slug' => 'suspended-company',
                'name' => 'Suspended Company',
            ])->id,
            'role' => 'billing_user',
            'status' => 'suspended',
        ]);

        $this->assertTrue($policy->canChangeActiveTenant($user, $activeMembership));
        $this->assertFalse($policy->canChangeActiveTenant($user, $suspendedMembership));
    }

    public function test_fiscal_role_mapping_is_derived_from_platform_tenant_role(): void
    {
        $policy = app(PlatformAccessPolicy::class);
        [$owner, $tenant] = $this->userWithTenantRole('owner');
        [$billingUser] = $this->userWithTenantRole('billing_user', $tenant);
        [$viewer] = $this->userWithTenantRole('viewer', $tenant);
        [$seller] = $this->userWithTenantRole('seller', $tenant);
        [$accountant] = $this->userWithTenantRole('accountant', $tenant);

        $this->assertSame('company_admin', $policy->fiscalRoleFor(
            $owner->memberships()->where('tenant_id', $tenant->id)->firstOrFail()
        ));
        $this->assertSame('cashier', $policy->fiscalRoleFor(
            $billingUser->memberships()->where('tenant_id', $tenant->id)->firstOrFail()
        ));
        $this->assertSame('viewer', $policy->fiscalRoleFor(
            $viewer->memberships()->where('tenant_id', $tenant->id)->firstOrFail()
        ));
        $this->assertSame('billing_user', $policy->fiscalRoleFor(
            $seller->memberships()->where('tenant_id', $tenant->id)->firstOrFail()
        ));
        $this->assertSame('viewer', $policy->fiscalRoleFor(
            $accountant->memberships()->where('tenant_id', $tenant->id)->firstOrFail()
        ));
    }

    private function userWithMembership(string $role): User
    {
        return $this->userWithTenantRole($role)[0];
    }

    /**
     * @return array{0: User, 1: Tenant}
     */
    private function userWithTenantRole(string $role, ?Tenant $tenant = null): array
    {
        $tenant ??= Tenant::query()->create([
            'slug' => fake()->unique()->slug(2),
            'name' => fake()->company(),
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

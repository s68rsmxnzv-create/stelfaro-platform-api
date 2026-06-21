<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlatformUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_owner_can_list_global_users(): void
    {
        $owner = User::factory()->create(['platform_role' => 'platform_owner']);
        User::factory()->create(['email' => 'cliente@example.test']);

        $this->actingAs($owner)
            ->getJson('/api/v1/admin/platform/users')
            ->assertOk()
            ->assertJsonFragment(['email' => 'cliente@example.test']);
    }

    public function test_platform_owner_bootstrap_email_can_list_global_users(): void
    {
        config(['platform.admin.platform_emails' => ['owner@example.test']]);

        $owner = User::factory()->create(['email' => 'owner@example.test']);
        User::factory()->create(['email' => 'cliente@example.test']);

        $this->actingAs($owner)
            ->getJson('/api/v1/admin/platform/users')
            ->assertOk()
            ->assertJsonFragment(['email' => 'cliente@example.test']);
    }

    public function test_company_admin_cannot_list_global_users(): void
    {
        $companyAdmin = $this->userWithRole('company_admin');

        $this->actingAs($companyAdmin)
            ->getJson('/api/v1/admin/platform/users')
            ->assertForbidden();
    }

    public function test_company_owner_cannot_list_global_users_without_platform_role(): void
    {
        $companyOwner = $this->userWithRole('owner');

        $this->actingAs($companyOwner)
            ->getJson('/api/v1/admin/platform/users')
            ->assertForbidden();
    }

    public function test_company_owner_can_invite_user_only_to_their_company(): void
    {
        $this->fakeNotifications();
        [$owner, $tenant] = $this->userWithTenantRole('owner');
        $otherTenant = Tenant::query()->create([
            'slug' => 'otra-empresa',
            'name' => 'Otra Empresa',
        ]);

        $response = $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/invitations", [
                'email' => 'cajero@example.test',
                'role' => 'billing_user',
            ])
            ->assertCreated()
            ->assertJsonPath('invitation.email', 'cajero@example.test')
            ->assertJsonPath('invitation.role', 'billing_user');

        $this->assertNotEmpty($response->json('token'));
        $this->assertDatabaseHas('user_invitations', [
            'tenant_id' => $tenant->id,
            'email' => 'cajero@example.test',
            'role' => 'billing_user',
            'status' => 'pending',
        ]);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://notifications.test/api/v1/platform/invitations/email'
            && $request['recipient']['email'] === 'cajero@example.test'
            && $request['tenant']['id'] === $tenant->id);

        $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$otherTenant->id}/invitations", [
                'email' => 'otro@example.test',
                'role' => 'billing_user',
            ])
            ->assertForbidden();
    }

    public function test_billing_user_cannot_invite_users(): void
    {
        [$billingUser, $tenant] = $this->userWithTenantRole('billing_user');

        $this->actingAs($billingUser)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/invitations", [
                'email' => 'nuevo@example.test',
                'role' => 'viewer',
            ])
            ->assertForbidden();
    }

    public function test_invited_user_can_accept_pending_invitation(): void
    {
        $this->fakeNotifications();
        [$owner, $tenant] = $this->userWithTenantRole('owner');
        $token = $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/invitations", [
                'email' => 'contador@example.test',
                'role' => 'viewer',
            ])
            ->json('token');
        $invitee = User::factory()->create(['email' => 'contador@example.test']);

        $this->actingAs($invitee)
            ->postJson("/api/v1/platform/invitations/{$token}/accept")
            ->assertOk()
            ->assertJsonPath('invitation.status', 'accepted');

        $this->assertDatabaseHas('user_tenant_memberships', [
            'tenant_id' => $tenant->id,
            'user_id' => $invitee->id,
            'role' => 'viewer',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('user_invitations', [
            'email' => 'contador@example.test',
            'status' => 'accepted',
        ]);
    }

    public function test_expired_invitation_cannot_be_accepted(): void
    {
        $tenant = Tenant::query()->create([
            'slug' => 'empresa-expirada',
            'name' => 'Empresa Expirada',
        ]);
        $token = 'expired-token';
        UserInvitation::query()->create([
            'tenant_id' => $tenant->id,
            'email' => 'expirado@example.test',
            'role' => 'viewer',
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->subMinute(),
            'status' => 'pending',
        ]);
        $user = User::factory()->create(['email' => 'expirado@example.test']);

        $this->actingAs($user)
            ->postJson("/api/v1/platform/invitations/{$token}/accept")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('invitation');

        $this->assertDatabaseHas('user_invitations', [
            'email' => 'expirado@example.test',
            'status' => 'expired',
        ]);
    }

    public function test_expire_invitations_command_marks_pending_expired_invitations(): void
    {
        $tenant = Tenant::query()->create([
            'slug' => 'empresa-comando',
            'name' => 'Empresa Comando',
        ]);
        UserInvitation::query()->create([
            'tenant_id' => $tenant->id,
            'email' => 'expirada@example.test',
            'role' => 'viewer',
            'token_hash' => hash('sha256', 'expired-command-token'),
            'expires_at' => now()->subMinute(),
            'status' => 'pending',
        ]);
        UserInvitation::query()->create([
            'tenant_id' => $tenant->id,
            'email' => 'vigente@example.test',
            'role' => 'viewer',
            'token_hash' => hash('sha256', 'active-command-token'),
            'expires_at' => now()->addDay(),
            'status' => 'pending',
        ]);

        $this->artisan('platform:invitations:expire')
            ->expectsOutput('Expired 1 invitation(s).')
            ->assertExitCode(0);

        $this->assertDatabaseHas('user_invitations', [
            'email' => 'expirada@example.test',
            'status' => 'expired',
        ]);
        $this->assertDatabaseHas('user_invitations', [
            'email' => 'vigente@example.test',
            'status' => 'pending',
        ]);
    }

    public function test_resending_invitation_reuses_record_and_rotates_token(): void
    {
        $this->fakeNotifications();
        [$owner, $tenant] = $this->userWithTenantRole('owner');
        $response = $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/invitations", [
                'email' => 'pendiente@example.test',
                'role' => 'billing_user',
            ]);
        $invitation = UserInvitation::query()->where('email', 'pendiente@example.test')->firstOrFail();
        $originalHash = $invitation->token_hash;

        $resend = $this->actingAs($owner)
            ->postJson("/api/v1/platform/invitations/{$invitation->id}/resend")
            ->assertOk()
            ->assertJsonPath('invitation.id', $invitation->id);

        $this->assertNotSame($response->json('token'), $resend->json('token'));
        $this->assertSame(1, UserInvitation::query()->where('email', 'pendiente@example.test')->count());
        $this->assertNotSame($originalHash, $invitation->refresh()->token_hash);
    }

    public function test_company_admin_cannot_modify_company_owner_membership(): void
    {
        [$owner, $tenant] = $this->userWithTenantRole('owner');
        [$companyAdmin] = $this->userWithTenantRole('company_admin', $tenant);
        $ownerMembership = $owner->memberships()->where('tenant_id', $tenant->id)->firstOrFail();

        $this->actingAs($companyAdmin)
            ->patchJson("/api/v1/platform/memberships/{$ownerMembership->id}/role", [
                'role' => 'viewer',
            ])
            ->assertForbidden();
    }

    public function test_owner_can_suspend_reactivate_and_remove_company_member(): void
    {
        [$owner, $tenant] = $this->userWithTenantRole('owner');
        [$member] = $this->userWithTenantRole('billing_user', $tenant);
        $membership = $member->memberships()->where('tenant_id', $tenant->id)->firstOrFail();

        $this->actingAs($owner)
            ->patchJson("/api/v1/platform/memberships/{$membership->id}/suspend")
            ->assertOk()
            ->assertJsonPath('membership.status', 'suspended');

        $this->actingAs($owner)
            ->patchJson("/api/v1/platform/memberships/{$membership->id}/reactivate")
            ->assertOk()
            ->assertJsonPath('membership.status', 'active');

        $this->actingAs($owner)
            ->deleteJson("/api/v1/platform/memberships/{$membership->id}")
            ->assertOk()
            ->assertJsonPath('membership.status', 'removed');
    }

    public function test_user_can_change_active_tenant_to_active_membership_only(): void
    {
        [$user, $tenant] = $this->userWithTenantRole('billing_user');
        $active = $user->memberships()->where('tenant_id', $tenant->id)->firstOrFail();
        $suspendedTenant = Tenant::query()->create([
            'slug' => 'tenant-suspendido',
            'name' => 'Tenant Suspendido',
        ]);
        $suspended = $user->memberships()->create([
            'tenant_id' => $suspendedTenant->id,
            'role' => 'billing_user',
            'status' => 'suspended',
        ]);

        $this->actingAs($user)
            ->patchJson("/api/v1/me/active-membership/{$active->id}")
            ->assertOk()
            ->assertJsonPath('membership.is_default', true);

        $this->actingAs($user)
            ->patchJson("/api/v1/me/active-membership/{$suspended->id}")
            ->assertForbidden();
    }

    private function fakeNotifications(): void
    {
        config([
            'services.notifications.base_url' => 'https://notifications.test/api/v1',
            'services.notifications.internal_token' => 'notifications-secret',
        ]);

        Http::fake([
            'https://notifications.test/api/v1/platform/invitations/email' => Http::response(['queued' => true]),
        ]);
    }

    private function userWithRole(string $role): User
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

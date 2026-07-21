<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserSettingsAndRequestsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_update_personal_profile_without_changing_company_data(): void
    {
        $user = User::factory()->create(['name' => 'Nombre anterior', 'email' => 'old@example.test']);

        $this->actingAs($user)
            ->patchJson('/api/v1/me/profile', [
                'name' => 'Andrea Hernández',
                'email' => 'andrea@example.test',
                'phone' => '7000-0000',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Andrea Hernández')
            ->assertJsonPath('data.phone', '7000-0000');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'andrea@example.test', 'phone' => '7000-0000']);
    }

    public function test_password_change_records_date_and_closes_other_database_sessions(): void
    {
        config(['session.driver' => 'database', 'session.table' => 'sessions']);
        $user = User::factory()->create(['password' => Hash::make('Password-actual-2026')]);
        DB::table('sessions')->insert([
            'id' => 'other-session',
            'user_id' => $user->id,
            'ip_address' => '192.0.2.10',
            'user_agent' => 'Mozilla/5.0 Chrome/120 Windows',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($user)
            ->putJson('/api/v1/me/password', [
                'current_password' => 'Password-actual-2026',
                'password' => 'Password-nueva-2026',
                'password_confirmation' => 'Password-nueva-2026',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('Password-nueva-2026', $user->refresh()->password));
        $this->assertNotNull($user->password_changed_at);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-session']);
    }

    public function test_user_can_list_and_close_another_owned_session_only(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        foreach ([['owned-session', $user->id], ['foreign-session', $other->id]] as [$id, $userId]) {
            DB::table('sessions')->insert([
                'id' => $id,
                'user_id' => $userId,
                'ip_address' => '192.0.2.10',
                'user_agent' => 'Mozilla/5.0 Chrome/120 Windows',
                'payload' => '',
                'last_activity' => now()->timestamp,
            ]);
        }

        $this->actingAs($user)->getJson('/api/v1/me/security')
            ->assertOk()
            ->assertJsonFragment(['id' => 'owned-session', 'device' => 'Chrome · Windows']);
        $this->actingAs($user)->deleteJson('/api/v1/me/security/sessions/owned-session')->assertOk();
        $this->actingAs($user)->deleteJson('/api/v1/me/security/sessions/foreign-session')->assertNotFound();
        $this->assertDatabaseHas('sessions', ['id' => 'foreign-session']);
    }

    public function test_company_admin_can_submit_idempotent_request_and_platform_owner_can_complete_it(): void
    {
        [$admin, $tenant] = $this->tenantUser('company_admin');
        $owner = User::factory()->create(['platform_role' => 'platform_owner']);
        $payload = [
            'idempotency_key' => '495ba25a-a076-4b76-b15b-1b16bc58d440',
            'type' => 'branch',
            'subject' => 'Nueva sucursal Santa Ana',
            'description' => 'Necesitamos habilitar una segunda ubicación.',
            'payload' => ['name' => 'Santa Ana', 'address' => 'Centro'],
        ];

        $first = $this->actingAs($admin)->postJson("/api/v1/platform/tenants/{$tenant->id}/requests", $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');
        $this->actingAs($admin)->postJson("/api/v1/platform/tenants/{$tenant->id}/requests", $payload)->assertOk();
        $this->assertDatabaseCount('tenant_requests', 1);
        $this->assertDatabaseHas('internal_notifications', [
            'user_id' => $owner->id,
            'tenant_id' => $tenant->id,
            'category' => 'tenant_request',
            'action_url' => 'https://admin.stelfaro.com/requests?request='.$first->json('data.id'),
        ]);

        $requestId = $first->json('data.id');
        $this->actingAs($owner)
            ->patchJson("/api/v1/admin/platform/requests/{$requestId}", [
                'status' => 'completed',
                'admin_response' => 'Sucursal creada y habilitada.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertDatabaseHas('tenant_requests', ['id' => $requestId, 'assigned_to_user_id' => $owner->id, 'status' => 'completed']);
        $this->assertDatabaseHas('internal_notifications', ['user_id' => $admin->id, 'tenant_id' => $tenant->id, 'category' => 'tenant_request']);
    }

    public function test_billing_user_cannot_submit_or_view_company_requests(): void
    {
        [$user, $tenant] = $this->tenantUser('billing_user');

        $this->actingAs($user)->getJson("/api/v1/platform/tenants/{$tenant->id}/requests")->assertForbidden();
        $this->actingAs($user)->postJson("/api/v1/platform/tenants/{$tenant->id}/requests", [
            'idempotency_key' => '495ba25a-a076-4b76-b15b-1b16bc58d441',
            'type' => 'support',
            'subject' => 'Ayuda',
        ])->assertForbidden();
    }

    /** @return array{User, Tenant} */
    private function tenantUser(string $role): array
    {
        $tenant = Tenant::query()->create(['slug' => 'empresa-prueba', 'name' => 'Empresa Prueba']);
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

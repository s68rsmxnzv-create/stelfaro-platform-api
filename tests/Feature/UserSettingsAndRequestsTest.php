<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
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
            'payload' => [
                'name' => 'Santa Ana',
                'address' => 'Centro',
                'department' => 'Santa Ana',
                'municipality' => 'Santa Ana',
            ],
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
            'action_url' => 'https://new.stelfaro.com/administracion/requests?request='.$first->json('data.id'),
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

    public function test_platform_owner_can_fulfill_user_request_and_requester_can_reveal_temporary_credentials(): void
    {
        [$admin, $tenant] = $this->tenantUser('company_admin');
        $owner = User::factory()->create(['platform_role' => 'platform_owner']);
        $requestId = $this->actingAs($admin)->postJson("/api/v1/platform/tenants/{$tenant->id}/requests", [
            'idempotency_key' => '495ba25a-a076-4b76-b15b-1b16bc58d499',
            'type' => 'user_access',
            'subject' => 'Crear usuario de caja',
            'payload' => ['action' => 'create', 'name' => 'Ana Caja', 'email' => 'ana.caja@example.test', 'phone' => '7000-1234', 'role' => 'billing_user'],
        ])->assertCreated()->json('data.id');

        $this->actingAs($owner)->patchJson("/api/v1/admin/platform/requests/{$requestId}", [
            'status' => 'approved',
            'admin_response' => 'Solicitud aprobada.',
        ])->assertOk();
        $created = $this->postJson("/api/v1/admin/platform/requests/{$requestId}/create-user", [
            'name' => 'Ana Caja', 'email' => 'ana.caja@example.test', 'phone' => '7000-1234', 'role' => 'billing_user',
        ])->assertCreated()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.fulfillment.credentials_available', true);

        $this->assertNotEmpty($created->json('temporary_password'));
        $this->assertDatabaseHas('users', ['email' => 'ana.caja@example.test', 'phone' => '7000-1234']);
        $this->assertDatabaseHas('tenant_requests', ['id' => $requestId, 'status' => 'completed']);
        $this->assertDatabaseHas('internal_notifications', ['user_id' => $admin->id, 'category' => 'tenant_request']);

        $this->actingAs($admin)->postJson("/api/v1/platform/tenants/{$tenant->id}/requests/{$requestId}/credentials")
            ->assertOk()
            ->assertJsonPath('data.email', 'ana.caja@example.test')
            ->assertJsonPath('data.temporary_password', $created->json('temporary_password'));
    }

    public function test_opening_request_starts_review_and_owner_can_correct_and_create_branch(): void
    {
        config([
            'services.dte_core.base_url' => 'https://core.test/api/v1',
            'services.dte_core.internal_token' => 'internal-secret',
            'services.dte_core.admin_email' => 'admin@stelfaro.com',
        ]);
        Http::fake([
            'https://core.test/api/v1/internal/auth/billing-session' => Http::response(['token' => 'backoffice-token']),
            'https://core.test/api/v1/billing/companies/77/sucursales' => Http::response(['empresa' => ['sucursales' => [[
                'id' => 501, 'codigo' => 'S002', 'nombre' => 'Sucursal Santa Ana', 'puntosVenta' => [['id' => 601, 'codigo' => 'P001']],
            ]]]], 201),
        ]);
        [$admin, $tenant] = $this->tenantUser('company_admin');
        $tenant->forceFill(['metadata' => ['core_empresa_id' => 77]])->save();
        $owner = User::factory()->create(['platform_role' => 'platform_owner']);
        $requestId = $this->actingAs($admin)->postJson("/api/v1/platform/tenants/{$tenant->id}/requests", [
            'idempotency_key' => '495ba25a-a076-4b76-b15b-1b16bc58d498', 'type' => 'branch', 'subject' => 'Nueva sucursal',
            'payload' => ['name' => 'Santa Ana', 'establishment_type' => 'branch', 'address' => 'Centro', 'department' => 'Santa Ana', 'municipality' => 'Santa Ana'],
        ])->assertCreated()->json('data.id');

        $this->actingAs($owner)->postJson("/api/v1/admin/platform/requests/{$requestId}/review")
            ->assertOk()->assertJsonPath('data.status', 'in_review');
        $this->postJson("/api/v1/admin/platform/requests/{$requestId}/create-branch", [
            'nombre' => 'Sucursal Santa Ana', 'codigo' => 'S002', 'direccion' => 'Centro', 'departamento' => 'Santa Ana',
            'municipio' => 'Santa Ana', 'punto_venta_codigo' => 'P001', 'punto_venta_nombre' => 'Caja principal',
        ])->assertCreated()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.fulfillment.resource_id', '501');

        $this->assertDatabaseHas('tenant_requests', ['id' => $requestId, 'fulfilled_resource_type' => 'branch', 'fulfilled_resource_id' => '501']);
        Http::assertSent(fn ($request) => $request->url() === 'https://core.test/api/v1/billing/companies/77/sucursales'
            && $request->hasHeader('Authorization', 'Bearer backoffice-token')
            && $request['codigo'] === 'S002');
    }

    public function test_owner_can_only_create_requested_point_in_a_branch_owned_by_the_tenant(): void
    {
        config([
            'services.dte_core.base_url' => 'https://core.test/api/v1',
            'services.dte_core.internal_token' => 'internal-secret',
            'services.dte_core.admin_email' => 'admin@stelfaro.com',
        ]);
        Http::fake([
            'https://core.test/api/v1/internal/billing/companies/77/fiscal-scope' => Http::response([
                'sucursales' => [['id' => 501, 'nombre' => 'Casa matriz', 'puntos_venta' => []]],
            ]),
            'https://core.test/api/v1/internal/auth/billing-session' => Http::response(['token' => 'backoffice-token']),
            'https://core.test/api/v1/billing/sucursales/501/puntos-venta' => Http::response(['empresa' => ['sucursales' => [[
                'id' => 501, 'puntosVenta' => [['id' => 602, 'codigo' => 'P002']],
            ]]]], 201),
        ]);
        [$admin, $tenant] = $this->tenantUser('company_admin');
        $tenant->forceFill(['metadata' => ['core_empresa_id' => 77]])->save();
        $owner = User::factory()->create(['platform_role' => 'platform_owner']);
        $requestId = $this->actingAs($admin)->postJson("/api/v1/platform/tenants/{$tenant->id}/requests", [
            'idempotency_key' => '495ba25a-a076-4b76-b15b-1b16bc58d497', 'type' => 'point_of_sale', 'subject' => 'Nueva caja',
            'payload' => ['name' => 'Caja dos', 'branch_id' => 501, 'point_type' => 'terminal'],
        ])->assertCreated()->json('data.id');

        $this->actingAs($owner)->postJson("/api/v1/admin/platform/requests/{$requestId}/create-point-of-sale", [
            'sucursal_id' => 999, 'codigo' => 'P002', 'nombre' => 'Caja dos', 'tipo' => 'terminal',
        ])->assertUnprocessable();
        $this->postJson("/api/v1/admin/platform/requests/{$requestId}/create-point-of-sale", [
            'sucursal_id' => 501, 'codigo' => 'P002', 'nombre' => 'Caja dos', 'tipo' => 'terminal',
        ])->assertCreated()->assertJsonPath('data.fulfillment.resource_id', '602');
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

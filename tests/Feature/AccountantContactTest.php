<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountantContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_owner_can_manage_accountant_contacts(): void
    {
        [$owner, $tenant] = $this->userWithTenantRole('owner');

        $created = $this->actingAs($owner)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/accountant-contacts", [
                'name' => 'Contador Externo',
                'email' => 'contador@example.test',
                'phone' => '70001234',
            ])
            ->assertCreated()
            ->assertJsonPath('contact.name', 'Contador Externo')
            ->assertJsonPath('contact.email', 'contador@example.test');

        $contactId = $created->json('contact.id');

        $this->actingAs($owner)
            ->getJson("/api/v1/platform/tenants/{$tenant->id}/accountant-contacts")
            ->assertOk()
            ->assertJsonPath('contacts.0.id', $contactId);

        $this->actingAs($owner)
            ->patchJson("/api/v1/platform/tenants/{$tenant->id}/accountant-contacts/{$contactId}", [
                'phone' => '70009999',
            ])
            ->assertOk()
            ->assertJsonPath('contact.phone', '70009999');

        $this->actingAs($owner)
            ->deleteJson("/api/v1/platform/tenants/{$tenant->id}/accountant-contacts/{$contactId}")
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->assertDatabaseMissing('accountant_contacts', ['id' => $contactId]);
    }

    public function test_billing_user_cannot_manage_accountant_contacts(): void
    {
        [$billingUser, $tenant] = $this->userWithTenantRole('billing_user');

        $this->actingAs($billingUser)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/accountant-contacts", [
                'name' => 'Contador Externo',
                'email' => 'contador@example.test',
            ])
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: Tenant}
     */
    private function userWithTenantRole(string $role): array
    {
        $tenant = Tenant::query()->create([
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

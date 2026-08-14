<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use App\Models\UserTenantMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LegalAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_user_is_sent_to_legal_acceptance_after_changing_temporary_password(): void
    {
        [$user] = $this->productionUser([
            'password' => Hash::make('Temporal123'),
            'must_change_password' => true,
        ]);

        $this->actingAs($user)
            ->put('https://platform.stelfaro.com/change-temporary-password', [
                'current_password' => 'Temporal123',
                'password' => 'Nueva-clave-123',
                'password_confirmation' => 'Nueva-clave-123',
            ])
            ->assertRedirect('https://platform.stelfaro.com/aceptacion-legal');

        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertTrue(Hash::check('Nueva-clave-123', $user->fresh()->password));
    }

    public function test_test_environment_does_not_require_legal_acceptance(): void
    {
        $tenant = Tenant::query()->create([
            'slug' => 'empresa-pruebas',
            'name' => 'Empresa de pruebas',
            'metadata' => ['environment' => '00'],
        ]);
        $user = User::factory()->create();
        $user->memberships()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
            'status' => 'active',
            'is_default' => true,
        ]);

        $this->actingAs($user)
            ->getJson('https://platform.stelfaro.com/api/v1/me')
            ->assertOk();

        $this->assertDatabaseCount('legal_documents', 0);
        $this->assertDatabaseCount('legal_acceptances', 0);
    }

    public function test_protected_api_is_blocked_until_production_user_accepts(): void
    {
        [$user] = $this->productionUser();

        $this->actingAs($user)
            ->getJson('https://platform.stelfaro.com/api/v1/me')
            ->assertStatus(428)
            ->assertJson([
                'message' => 'Debes aceptar los documentos legales vigentes antes de continuar.',
                'redirect' => 'https://platform.stelfaro.com/aceptacion-legal',
            ]);
    }

    public function test_acceptance_screen_exposes_the_current_document_versions(): void
    {
        [$user, $tenant] = $this->productionUser();

        $this->actingAs($user)
            ->get('https://platform.stelfaro.com/aceptacion-legal')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/AcceptLegalDocuments')
                ->where('tenant.name', $tenant->name)
                ->where('tenant.environment', '01')
                ->where('acceptanceVersion', '2026-08-14-v1')
                ->has('documents', 2)
                ->where('documents.0.version', '2026-08-13')
                ->where('documents.1.version', '2026-08-13'));

        $this->assertDatabaseHas('legal_documents', [
            'type' => 'terms',
            'version' => '2026-08-13',
            'content_hash' => config('legal.documents.terms.content_hash'),
        ]);
    }

    public function test_acceptance_requires_the_current_password_and_all_declarations(): void
    {
        [$user] = $this->productionUser();

        $this->actingAs($user)
            ->from('https://platform.stelfaro.com/aceptacion-legal')
            ->post('https://platform.stelfaro.com/aceptacion-legal', $this->acceptancePayload([
                'current_password' => 'incorrecta',
                'authority_confirmed' => false,
            ]))
            ->assertRedirect('https://platform.stelfaro.com/aceptacion-legal')
            ->assertSessionHasErrors(['current_password', 'authority_confirmed']);

        $this->assertDatabaseCount('legal_acceptances', 0);
    }

    public function test_production_user_can_accept_with_password_and_evidence_is_preserved(): void
    {
        [$user, $tenant, $membership] = $this->productionUser([
            'password_changed_at' => now()->subMinute(),
        ]);

        $this->actingAs($user)
            ->post('https://platform.stelfaro.com/aceptacion-legal', $this->acceptancePayload())
            ->assertRedirect('https://platform.stelfaro.com');

        $this->assertDatabaseCount('legal_acceptances', 2);
        $this->assertDatabaseHas('legal_acceptances', [
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'membership_id' => $membership->id,
            'document_type' => 'terms',
            'document_version' => '2026-08-13',
            'acceptance_version' => '2026-08-14-v1',
            'environment' => '01',
            'role_at_acceptance' => 'owner',
            'authentication_method' => 'password_reentry',
        ]);

        $acceptances = $user->newQuery()
            ->from('legal_acceptances')
            ->where('user_id', $user->id)
            ->get();

        $this->assertCount(1, $acceptances->pluck('event_uuid')->unique());
        $this->assertNotEmpty($acceptances->first()->session_id_hash);
        $this->assertNotEmpty($acceptances->first()->request_id);
        $this->assertArrayNotHasKey('current_password', $acceptances->first()->getAttributes());

        $this->actingAs($user)
            ->getJson('https://platform.stelfaro.com/api/v1/me')
            ->assertOk();
    }

    public function test_a_new_document_version_requires_a_new_acceptance(): void
    {
        [$user] = $this->productionUser();

        $this->actingAs($user)
            ->post('https://platform.stelfaro.com/aceptacion-legal', $this->acceptancePayload())
            ->assertRedirect();

        config()->set('legal.documents.terms.version', '2026-08-14');

        $this->actingAs($user)
            ->getJson('https://platform.stelfaro.com/api/v1/me')
            ->assertStatus(428);

        $this->assertDatabaseHas('legal_documents', [
            'type' => 'terms',
            'version' => '2026-08-14',
        ]);
    }

    public function test_configured_hashes_match_the_published_legal_documents(): void
    {
        foreach (config('legal.documents') as $definition) {
            $this->assertSame(
                $definition['content_hash'],
                hash_file('sha256', base_path($definition['source_path'])),
                "Actualiza la versión y el hash de {$definition['source_path']}.",
            );
        }
    }

    /**
     * @param  array<string, mixed>  $userAttributes
     * @return array{User, Tenant, UserTenantMembership}
     */
    private function productionUser(array $userAttributes = []): array
    {
        $tenant = Tenant::query()->create([
            'slug' => 'empresa-produccion-'.str()->random(8),
            'name' => 'Empresa en producción',
            'metadata' => ['environment' => '01'],
        ]);
        $user = User::factory()->create($userAttributes);
        $membership = $user->memberships()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
            'status' => 'active',
            'is_default' => true,
        ]);

        return [$user, $tenant, $membership];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function acceptancePayload(array $overrides = []): array
    {
        return [
            'current_password' => 'password',
            'accept_terms' => true,
            'accept_privacy' => true,
            'authority_confirmed' => true,
            'document_versions' => [
                'terms' => config('legal.documents.terms.version'),
                'privacy' => config('legal.documents.privacy.version'),
            ],
            ...$overrides,
        ];
    }
}

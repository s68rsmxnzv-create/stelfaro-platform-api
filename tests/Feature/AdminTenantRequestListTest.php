<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminTenantRequestListTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_paginates_requests_and_reports_stats_independent_of_the_page(): void
    {
        $owner = User::factory()->create(['platform_role' => 'platform_owner']);
        $tenant = Tenant::query()->create(['slug' => 'requests-tenant', 'name' => 'Requests Tenant']);
        $requester = User::factory()->create();

        foreach (range(1, 3) as $index) {
            $this->makeRequest($tenant, $requester, 'pending', "Pendiente {$index}");
        }
        $this->makeRequest($tenant, $requester, 'in_review', 'En revision');
        $this->makeRequest($tenant, $requester, 'completed', 'Completada');

        $response = $this->actingAs($owner)
            ->getJson('/api/v1/admin/platform/requests?per_page=2')
            ->assertOk();

        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('meta.total', 5);
        $response->assertJsonPath('meta.last_page', 3);
        // Los stats reflejan TODAS las solicitudes, no solo la pagina actual.
        $response->assertJsonPath('stats.pending', 3);
        $response->assertJsonPath('stats.in_review', 1);
        $response->assertJsonPath('stats.completed', 1);
    }

    public function test_index_can_look_up_a_single_request_by_id_regardless_of_page(): void
    {
        $owner = User::factory()->create(['platform_role' => 'platform_owner']);
        $tenant = Tenant::query()->create(['slug' => 'requests-lookup', 'name' => 'Requests Lookup']);
        $requester = User::factory()->create();

        $first = $this->makeRequest($tenant, $requester, 'pending', 'Primera');
        foreach (range(1, 5) as $index) {
            $this->makeRequest($tenant, $requester, 'pending', "Relleno {$index}");
        }

        // Con per_page bajo, "Primera" (la mas antigua) no caeria en la
        // pagina 1 por orden de fecha; el filtro id la debe encontrar igual.
        $response = $this->actingAs($owner)
            ->getJson("/api/v1/admin/platform/requests?id={$first->id}&per_page=2")
            ->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $first->id);
    }

    private function makeRequest(Tenant $tenant, User $requester, string $status, string $subject): TenantRequest
    {
        return TenantRequest::query()->create([
            'tenant_id' => $tenant->id,
            'requested_by_user_id' => $requester->id,
            'public_id' => (string) Str::uuid(),
            'type' => 'support',
            'status' => $status,
            'subject' => $subject,
        ]);
    }
}

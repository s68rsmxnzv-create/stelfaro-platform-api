<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AndroidPrintTest extends TestCase
{
    use RefreshDatabase;

    public function test_android_agent_can_pair_receive_shared_operations_and_confirm_printing(): void
    {
        $tenant = Tenant::query()->create(['slug' => 'android-print', 'name' => 'Android Print', 'status' => 'active']);
        $user = User::factory()->create(['email_verified_at' => now(), 'must_change_password' => false]);
        $user->memberships()->create([
            'tenant_id' => $tenant->id,
            'role' => 'company_admin',
            'status' => 'active',
            'is_default' => true,
        ]);

        $pairing = $this->actingAs($user)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/android-print/pairing-codes", [
                'agent_name' => 'Tablet caja',
            ])
            ->assertCreated()
            ->assertJsonPath('data.server_url', rtrim((string) config('app.url'), '/'))
            ->json('data');

        $paired = $this->postJson('/api/faroprint/pair', [
            'code' => $pairing['code'],
            'device_name' => 'Samsung Tab',
        ])->assertOk()->assertJsonPath('device_name', 'Tablet caja')->json();

        $agents = $this->actingAs($user)
            ->getJson("/api/v1/platform/tenants/{$tenant->id}/android-print/agents")
            ->assertOk()
            ->assertJsonPath('data.0.status', 'active')
            ->json('data');

        $jobId = $this->postJson("/api/v1/platform/tenants/{$tenant->id}/android-print/jobs", [
            'agent_id' => $agents[0]['id'],
            'paperWidth' => '58',
            'operations' => [
                ['name' => 'init', 'args' => []],
                ['name' => 'font', 'args' => ['B']],
                ['name' => 'text', 'args' => ['Misma plantilla']],
                ['name' => 'font', 'args' => ['A']],
                ['name' => 'cut', 'args' => [6]],
            ],
        ])->assertCreated()->json('job_id');

        $headers = ['Authorization' => 'Bearer '.$paired['bearer_token']];
        $this->withHeaders($headers)->getJson('/api/faroprint/jobs')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', (string) $jobId)
            ->assertJsonPath('0.paperWidth', '58')
            ->assertJsonPath('0.operations.1.name', 'text')
            ->assertJsonMissing(['name' => 'font']);

        $this->withHeaders($headers)->postJson("/api/faroprint/jobs/{$jobId}/printed")
            ->assertOk();
        $this->assertDatabaseHas('android_print_jobs', [
            'id' => $jobId,
            'status' => 'printed',
        ]);
    }

    public function test_pairing_code_is_single_use(): void
    {
        $tenant = Tenant::query()->create(['slug' => 'single-pair', 'name' => 'Single Pair', 'status' => 'active']);
        $user = User::factory()->create(['email_verified_at' => now(), 'must_change_password' => false]);
        $user->memberships()->create([
            'tenant_id' => $tenant->id,
            'role' => 'company_admin',
            'status' => 'active',
            'is_default' => true,
        ]);
        $code = $this->actingAs($user)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/android-print/pairing-codes")
            ->json('data.code');

        $this->postJson('/api/faroprint/pair', ['code' => $code])->assertOk();
        $this->postJson('/api/faroprint/pair', ['code' => $code])->assertUnprocessable();
    }
}

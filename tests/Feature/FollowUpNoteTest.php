<?php

namespace Tests\Feature;

use App\Models\FollowUpNote;
use App\Models\PlatformApp;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FollowUpNoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_note_is_idempotent_and_has_no_financial_inventory_or_fiscal_effects(): void
    {
        [$user, $tenant] = $this->member();
        $base = "/api/v1/platform/tenants/{$tenant->id}/follow-up-notes";
        $payload = [
            'idempotency_key' => 'd9d92e13-7281-4140-b290-88e296827d1f',
            'person_name' => 'Mario López',
            'title' => 'SIM entregada para prueba',
            'description' => 'Confirmar el viernes si aún la necesita.',
            'category' => 'commitment',
            'occurred_on' => '2026-07-22',
            'remind_at' => '2026-07-23 09:00:00',
        ];

        $this->actingAs($user)->postJson($base, $payload)->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->postJson($base, $payload)->assertOk();

        $this->assertDatabaseCount('follow_up_notes', 1);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('inventory_sales', 0);
        $this->assertDatabaseCount('sales_orders', 0);
    }

    public function test_due_note_creates_notification_and_resolution_preserves_history(): void
    {
        [$user, $tenant] = $this->member();
        $note = FollowUpNote::query()->create([
            'tenant_id' => $tenant->id,
            'idempotency_key' => '8b57061b-44fa-43a0-b8e0-94bdac56d227',
            'created_by' => $user->id,
            'person_name' => 'Ana Pérez',
            'title' => 'Confirmar devolución',
            'category' => 'loan',
            'occurred_on' => today(),
            'remind_at' => now()->subMinute(),
        ]);

        $this->artisan('follow-up-notes:remind')->assertSuccessful();
        $this->artisan('follow-up-notes:remind')->assertSuccessful();
        $this->assertDatabaseCount('internal_notifications', 1);
        $this->assertDatabaseHas('internal_notifications', ['user_id' => $user->id, 'source_type' => 'follow_up_note', 'source_id' => (string) $note->id]);

        $base = "/api/v1/platform/tenants/{$tenant->id}/follow-up-notes/{$note->id}";
        $this->actingAs($user)->postJson($base.'/resolve', ['resolution_type' => 'returned', 'resolution_note' => 'La SIM fue devuelta.'])
            ->assertOk()->assertJsonPath('data.status', 'resolved')->assertJsonPath('data.resolution.type', 'returned');
        $this->assertDatabaseHas('follow_up_notes', ['id' => $note->id, 'status' => 'resolved', 'resolution_type' => 'returned']);
        $this->assertDatabaseHas('internal_notifications', ['source_id' => (string) $note->id, 'read_at' => now()]);
        $this->deleteJson($base)->assertMethodNotAllowed();
    }

    public function test_discard_requires_reason_and_keeps_note(): void
    {
        [$user, $tenant] = $this->member();
        $note = FollowUpNote::query()->create(['tenant_id' => $tenant->id, 'idempotency_key' => '5cb8897c-e76f-4d5b-b827-b30c189d12a7', 'created_by' => $user->id, 'person_name' => 'Cliente', 'title' => 'Nota duplicada', 'category' => 'other', 'occurred_on' => today()]);
        $base = "/api/v1/platform/tenants/{$tenant->id}/follow-up-notes/{$note->id}/discard";

        $this->actingAs($user)->postJson($base, ['reason' => ''])->assertUnprocessable();
        $this->postJson($base, ['reason' => 'Se registró por error.'])->assertOk()->assertJsonPath('data.status', 'discarded');
        $this->assertDatabaseHas('follow_up_notes', ['id' => $note->id, 'status' => 'discarded']);
    }

    private function member(): array
    {
        $app = PlatformApp::query()->create(['key' => 'facturacion', 'name' => 'Facturación', 'host' => 'facturacion.stelfaro.com', 'default_path' => '/', 'status' => 'active']);
        $tenant = Tenant::query()->create(['slug' => 'follow-ups', 'name' => 'Seguimientos', 'status' => 'active', 'primary_app_id' => $app->id]);
        $tenant->appAccesses()->create(['platform_app_id' => $app->id, 'status' => 'active', 'is_default' => true]);
        $user = User::factory()->create(['email_verified_at' => now(), 'must_change_password' => false]);
        $user->memberships()->create(['tenant_id' => $tenant->id, 'role' => 'company_admin', 'status' => 'active', 'is_default' => true]);

        return [$user, $tenant];
    }
}

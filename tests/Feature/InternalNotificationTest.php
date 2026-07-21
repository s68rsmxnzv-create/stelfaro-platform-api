<?php

namespace Tests\Feature;

use App\Models\InternalNotification;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FiscalCalendarClient;
use App\Services\TaxDeadlineNotificationGenerator;
use App\Support\Platform\PlatformRoles;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class InternalNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_generator_creates_one_tax_reminder_per_active_member_without_duplicates(): void
    {
        [$user, $tenant] = $this->member();
        $calendar = Mockery::mock(FiscalCalendarClient::class);
        $calendar->shouldReceive('publishedDeadlines')->twice()->with(2026)->andReturn([[
            'id' => 81,
            'date' => '2026-08-20',
            'type' => 'declaration_deadline',
            'name' => 'Declaración y pago mensual del IVA',
            'form_code' => 'F-07',
            'active' => true,
        ]]);
        $calendar->shouldReceive('publishedDeadlines')->twice()->with(2027)->andReturn([]);
        $this->app->instance(FiscalCalendarClient::class, $calendar);

        $generator = app(TaxDeadlineNotificationGenerator::class);
        $today = CarbonImmutable::create(2026, 7, 19, 0, 0, 0, 'America/El_Salvador');

        $this->assertSame(1, $generator->generate($today));
        $this->assertSame(0, $generator->generate($today));
        $this->assertDatabaseHas('internal_notifications', [
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'category' => 'tax_deadline',
            'due_date' => '2026-08-20',
        ]);
    }

    public function test_member_can_list_and_mark_own_notifications_as_read(): void
    {
        [$user, $tenant] = $this->member();
        $notification = InternalNotification::query()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'category' => 'tax_deadline',
            'title' => 'Próximo vencimiento F-07',
            'message' => 'La declaración vence pronto.',
            'due_date' => '2026-08-20',
            'dedupe_key' => 'test-notification',
        ]);

        $this->actingAs($user)
            ->getJson("/api/v1/platform/notifications?tenant_id={$tenant->id}")
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('data.0.id', $notification->id);

        $this->postJson("/api/v1/platform/notifications/{$notification->id}/read")
            ->assertOk()
            ->assertJsonPath('data.read_at', fn ($value) => is_string($value));

        $this->getJson("/api/v1/platform/notifications?tenant_id={$tenant->id}")
            ->assertOk()
            ->assertJsonPath('unread_count', 0);
    }

    public function test_notification_inbox_is_isolated_by_tenant_membership(): void
    {
        [$user] = $this->member();
        $other = Tenant::query()->create(['slug' => 'other-notifications', 'name' => 'Otra empresa', 'status' => 'active']);

        $this->actingAs($user)
            ->getJson("/api/v1/platform/notifications?tenant_id={$other->id}")
            ->assertForbidden();
    }

    public function test_platform_owner_can_use_global_inbox_and_delete_own_notification(): void
    {
        $owner = User::factory()->create(['platform_role' => PlatformRoles::PLATFORM_OWNER]);
        $tenant = Tenant::query()->create(['slug' => 'admin-notifications', 'name' => 'Empresa solicitante', 'status' => 'active']);
        $notification = InternalNotification::query()->create([
            'user_id' => $owner->id,
            'tenant_id' => $tenant->id,
            'category' => 'tenant_request',
            'title' => 'Nueva solicitud',
            'message' => 'La empresa envió una solicitud.',
            'action_url' => '/requests?request=10',
            'dedupe_key' => 'admin-notification',
        ]);

        $this->actingAs($owner)
            ->getJson('/api/v1/platform/notifications?scope=admin')
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('data.0.action_url', 'https://admin.stelfaro.com/requests?request=10');

        $this->deleteJson("/api/v1/platform/notifications/{$notification->id}")
            ->assertOk();
        $this->assertDatabaseMissing('internal_notifications', ['id' => $notification->id]);
    }

    public function test_admin_can_mark_only_request_notifications_as_read(): void
    {
        $owner = User::factory()->create(['platform_role' => PlatformRoles::PLATFORM_OWNER]);
        $tenant = Tenant::query()->create(['slug' => 'filtered-notifications', 'name' => 'Empresa', 'status' => 'active']);
        foreach (['tenant_request', 'tax_deadline'] as $category) {
            InternalNotification::query()->create([
                'user_id' => $owner->id,
                'tenant_id' => $tenant->id,
                'category' => $category,
                'title' => 'Aviso',
                'message' => 'Mensaje.',
                'dedupe_key' => 'filtered-'.$category,
            ]);
        }

        $this->actingAs($owner)
            ->postJson('/api/v1/platform/notifications/read-all', ['scope' => 'admin', 'category' => 'tenant_request'])
            ->assertOk();

        $this->assertDatabaseMissing('internal_notifications', ['category' => 'tenant_request', 'read_at' => null]);
        $this->assertDatabaseHas('internal_notifications', ['category' => 'tax_deadline', 'read_at' => null]);
    }

    public function test_user_cannot_delete_another_users_notification(): void
    {
        [$user, $tenant] = $this->member();
        $other = User::factory()->create();
        $notification = InternalNotification::query()->create([
            'user_id' => $other->id,
            'tenant_id' => $tenant->id,
            'category' => 'tenant_request',
            'title' => 'Privada',
            'message' => 'Solo para otro usuario.',
            'dedupe_key' => 'foreign-notification',
        ]);

        $this->actingAs($user)
            ->deleteJson("/api/v1/platform/notifications/{$notification->id}")
            ->assertNotFound();
    }

    /** @return array{User, Tenant} */
    private function member(): array
    {
        $user = User::factory()->create();
        $tenant = Tenant::query()->create(['slug' => 'tax-reminders', 'name' => 'Empresa fiscal', 'status' => 'active']);
        $user->memberships()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
            'status' => 'active',
            'is_default' => true,
        ]);

        return [$user, $tenant];
    }
}

<?php

namespace Tests\Feature;

use App\Models\InternalNotification;
use App\Models\PlatformApp;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillCashNotificationActionUrlsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_migration_rewrites_existing_bare_caja_action_urls(): void
    {
        $app = PlatformApp::query()->create(['key' => 'taller', 'name' => 'Taller', 'host' => 'new.stelfaro.com', 'default_path' => '/', 'status' => 'active']);
        $tenant = Tenant::query()->create(['slug' => 'legacy-cash-company', 'name' => 'Legacy Cash Company', 'status' => 'active', 'primary_app_id' => $app->id]);
        $tenant->appAccesses()->create(['platform_app_id' => $app->id, 'status' => 'active', 'is_default' => true]);
        $user = User::factory()->create();

        $stale = InternalNotification::query()->create([
            'dedupe_key' => 'cash:cutoff:1:'.$user->id,
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'category' => 'cash',
            'title' => 'Caja pendiente de conteo',
            'message' => 'Caja matriz · El corte esperaba 25.00 USD. Confirma el efectivo contado.',
            'action_url' => '/caja',
            'source_type' => 'cash_session',
            'source_id' => '1',
        ]);
        $unrelated = InternalNotification::query()->create([
            'dedupe_key' => 'tenant_request:1:'.$user->id,
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'category' => 'tenant_request',
            'title' => 'Otra notificación',
            'message' => 'No debe tocarse.',
            'action_url' => '/requests?request=1',
            'source_type' => 'tenant_request',
            'source_id' => '1',
        ]);

        (require database_path('migrations/2026_09_01_140000_backfill_cash_notification_action_urls.php'))->up();

        $this->assertSame('https://new.stelfaro.com/taller/caja', $stale->fresh()->action_url);
        $this->assertSame('/requests?request=1', $unrelated->fresh()->action_url);
    }
}

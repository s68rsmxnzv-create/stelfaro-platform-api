<?php

namespace Tests\Feature;

use App\Models\PlatformApp;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkshopCustomer;
use App\Models\WorkshopDevice;
use App\Models\WorkshopOrder;
use App\Models\WorkshopPhotoSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkshopPhotoUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_temporary_token_uploads_photo_to_private_storage(): void
    {
        Storage::fake('local');
        [$user, $tenant, $order] = $this->order();
        $session = $this->actingAs($user)->postJson("/api/v1/platform/tenants/{$tenant->id}/workshop/orders/{$order->id}/photo-session")
            ->assertCreated()->json('data');
        $token = basename($session['url']);
        $page = $this->get("https://new.stelfaro.com/taller/fotos/{$token}")
            ->assertOk()
            ->assertSee($order->device->brand);
        preg_match("/script-src[^;]*'nonce-([^']+)'/", (string) $page->headers->get('Content-Security-Policy'), $nonce);
        $this->assertNotEmpty($nonce[1] ?? null);
        $page->assertSee('nonce="'.($nonce[1] ?? '').'"', false);

        $this->postJson("/api/v1/workshop/photo-upload/{$token}", [
            'photos' => [UploadedFile::fake()->image('equipo.jpg', 800, 600)],
        ])->assertCreated()->assertJsonPath('total', 1);

        $photo = $order->photos()->firstOrFail();
        Storage::disk('local')->assertExists($photo->path);
        $this->assertSame(64, strlen($photo->sha256));
        $stored = new \Imagick(Storage::disk('local')->path($photo->path));
        $this->assertSame(4 / 3, $stored->getImageWidth() / $stored->getImageHeight());
        $this->assertSame('image/jpeg', $photo->mime_type);

        $gallery = $this->getJson("/api/v1/platform/tenants/{$tenant->id}/workshop/orders/{$order->id}/photos")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $photo->id)
            ->assertJsonPath('data.0.stage', 'reception');
        $this->get($gallery->json('data.0.url'))
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');
        $publicPage = $this->get("https://new.stelfaro.com/taller/fotos/{$token}")
            ->assertOk()
            ->assertSee('Fotografías del equipo')
            ->assertSee('1 fotos guardadas.');
        $publicImageUrl = route('workshop.photos.image', ['token' => $token, 'photo' => $photo]);
        $publicPage->assertSee($publicImageUrl, false);
        $publicPage->assertSee('id="gallery-viewer"', false)->assertDontSee('target="_blank"', false);
        $this->get($publicImageUrl)->assertOk()->assertHeader('content-type', 'image/jpeg');
        $this->get($publicImageUrl.'?download=1')->assertOk()->assertHeader('content-disposition');
        $this->deleteJson("/api/v1/workshop/photo-upload/{$token}/{$photo->id}")->assertOk()->assertJsonPath('deleted', true);
        Storage::disk('local')->assertMissing($photo->path);
    }

    public function test_invalid_token_cannot_upload(): void
    {
        $this->postJson('/api/v1/workshop/photo-upload/invalid', [
            'photos' => [UploadedFile::fake()->image('equipo.jpg')],
        ])->assertNotFound();
    }

    public function test_expired_token_cannot_upload(): void
    {
        [, $tenant, $order] = $this->order();
        $token = 'expired-token';
        WorkshopPhotoSession::query()->create(['tenant_id' => $tenant->id, 'workshop_order_id' => $order->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->subMinute()]);

        $this->postJson("/api/v1/workshop/photo-upload/{$token}", [
            'photos' => [UploadedFile::fake()->image('equipo.jpg')],
        ])->assertNotFound();
    }

    private function order(): array
    {
        $tenant = Tenant::query()->create(['slug' => 'photos', 'name' => 'Photos', 'status' => 'active']);
        $taller = PlatformApp::query()->firstOrCreate(
            ['key' => 'taller'],
            ['name' => 'Taller electrónico', 'host' => 'new.stelfaro.com', 'default_path' => '/', 'status' => 'active'],
        );
        $tenant->appAccesses()->create([
            'platform_app_id' => $taller->id,
            'status' => 'active',
            'is_default' => true,
        ]);
        $user = User::factory()->create(['email_verified_at' => now(), 'must_change_password' => false]);
        $user->memberships()->create(['tenant_id' => $tenant->id, 'role' => 'company_admin', 'status' => 'active', 'is_default' => true]);
        $customer = WorkshopCustomer::query()->create(['tenant_id' => $tenant->id, 'core_customer_id' => 1, 'name' => 'Cliente']);
        $device = WorkshopDevice::query()->create(['tenant_id' => $tenant->id, 'workshop_customer_id' => $customer->id, 'type' => 'phone', 'brand' => 'Apple', 'model' => 'iPhone', 'power_status' => 'on']);
        $order = WorkshopOrder::query()->create(['tenant_id' => $tenant->id, 'workshop_device_id' => $device->id, 'received_by' => $user->id, 'ticket_number' => 1, 'status' => 'received', 'priority' => 'normal', 'reported_fault' => 'Falla', 'received_at' => now()]);

        return [$user, $tenant, $order];
    }
}

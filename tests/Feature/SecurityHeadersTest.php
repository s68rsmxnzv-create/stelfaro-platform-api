<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_responses_include_security_headers(): void
    {
        $this->get('/payments/wompi/return?idTransaccion=txn-123')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Content-Security-Policy');
    }

    public function test_suspicious_script_payload_is_blocked_with_clear_message(): void
    {
        $tenant = Tenant::query()->create([
            'slug' => 'cliente-seguro',
            'name' => 'Cliente Seguro',
        ]);
        $user = User::factory()->create();
        $user->memberships()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
            'status' => 'active',
            'is_default' => true,
        ]);

        $this->actingAs($user)
            ->postJson("/api/v1/platform/tenants/{$tenant->id}/users", [
                'name' => '<script>alert(1)</script>',
                'email' => 'nuevo@stelfaro.test',
                'role' => 'member',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Intento bloqueado. En Stelfaro protegemos a nuestros clientes; por aquí no se juega.')
            ->assertJsonPath('details', 'Tu IP, navegador, ruta y hora quedaron registrados para auditoría de seguridad.')
            ->assertJsonPath('field', 'name');

        $this->assertDatabaseHas('security_events', [
            'user_id' => $user->id,
            'type' => 'suspicious_input',
            'severity' => 'high',
            'field' => 'name',
            'method' => 'POST',
        ]);

        $event = DB::table('security_events')->latest('id')->first();
        $metadata = json_decode((string) $event->metadata, true);

        $this->assertArrayHasKey('input_sha256', $metadata);
        $this->assertArrayHasKey('input_length', $metadata);
        $this->assertArrayHasKey('pattern', $metadata);
        $this->assertArrayNotHasKey('input_sample', $metadata);
        $this->assertStringNotContainsString('<script>', json_encode($metadata));
    }

    public function test_suspicious_web_payload_gets_custom_block_page_without_framework_details(): void
    {
        $this->withHeader('User-Agent', 'Bad Browser 1.0')
            ->post('/login', [
                'email' => '<script>alert(1)</script>',
                'password' => 'password',
            ])
            ->assertStatus(422)
            ->assertSee('Intento bloqueado. En Stelfaro protegemos a nuestros clientes; por aquí no se juega.', false)
            ->assertSee('Tu IP, navegador, ruta y hora quedaron registrados', false)
            ->assertDontSee('Symfony', false)
            ->assertDontSee('Laravel', false)
            ->assertDontSee('vendor/', false);

        $this->assertDatabaseHas('security_events', [
            'type' => 'suspicious_input',
            'severity' => 'high',
            'field' => 'email',
            'method' => 'POST',
            'user_agent' => 'Bad Browser 1.0',
        ]);
    }

    public function test_wompi_webhook_is_not_blocked_by_suspicious_input_filter(): void
    {
        config(['services.wompi.api_secret' => null]);

        $this->postJson('/api/v1/webhooks/wompi', [
            'IdTransaccion' => 'txn-webhook-script',
            'ResultadoTransaccion' => 'Fallida',
            'cliente' => [
                'Email' => '<script>alert(1)</script>',
            ],
        ])->assertAccepted()
            ->assertJsonPath('status', 'received_unverified');
    }
}

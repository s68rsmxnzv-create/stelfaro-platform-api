<?php

namespace Tests\Feature;

use App\Models\PlatformApp;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WompiPaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_approved_wompi_webhook_activates_professional_subscription_by_customer_email(): void
    {
        config([
            'services.wompi.api_secret' => 'wompi-secret',
            'services.wompi.professional_annual_amount' => '224.87',
        ]);

        PlatformApp::query()->create(['key' => 'facturacion', 'name' => 'Facturacion']);
        PlatformApp::query()->create(['key' => 'taller', 'name' => 'Taller']);

        $tenant = Tenant::query()->create([
            'slug' => 'servicio-tecnico-el-faro',
            'name' => 'Servicio Tecnico El Faro',
        ]);
        $user = User::factory()->create(['email' => 'cliente@stelfaro.test']);
        $user->memberships()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
            'status' => 'active',
            'is_default' => true,
        ]);

        $payload = $this->approvedPayload(['cliente' => ['Email' => 'cliente@stelfaro.test']]);
        $this->postWompiWebhook($payload)
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $plan = SubscriptionPlan::query()->where('key', 'pro')->firstOrFail();
        $this->assertDatabaseHas('tenant_subscriptions', [
            'tenant_id' => $tenant->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'annual',
            'price_cents' => 19900,
        ]);
        $this->assertDatabaseHas('wompi_payment_events', [
            'tenant_id' => $tenant->id,
            'transaction_id' => 'txn-123',
            'customer_email' => 'cliente@stelfaro.test',
            'status' => 'processed',
            'hash_valid' => true,
        ]);
        $this->assertDatabaseHas('tenant_app_accesses', [
            'tenant_id' => $tenant->id,
            'status' => 'active',
        ]);
    }

    public function test_emprendedor_wompi_link_activates_starter_annual_subscription(): void
    {
        config([
            'services.wompi.api_secret' => 'wompi-secret',
            'services.wompi.payment_links.emprendedor.expected_amount' => '111.87',
        ]);

        PlatformApp::query()->create(['key' => 'facturacion', 'name' => 'Facturacion']);

        $tenant = Tenant::query()->create([
            'slug' => 'cliente-emprendedor',
            'name' => 'Cliente Emprendedor',
        ]);
        $user = User::factory()->create(['email' => 'emprendedor@stelfaro.test']);
        $user->memberships()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
            'status' => 'active',
            'is_default' => true,
        ]);

        $payload = $this->approvedPayload([
            'Monto' => '111.87',
            'IdTransaccion' => 'txn-emprendedor-123',
            'IdIntentoPago' => 'attempt-emprendedor-123',
            'EnlacePago' => [
                'Id' => 'bdfd9af6-ace2-48b1-92e4-07bd182619db',
                'IdentificadorEnlaceComercio' => null,
                'NombreProducto' => 'Emprendedor anual',
            ],
            'cliente' => ['Email' => 'emprendedor@stelfaro.test'],
        ]);

        $this->postWompiWebhook($payload)
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $plan = SubscriptionPlan::query()->where('key', 'starter')->firstOrFail();
        $this->assertDatabaseHas('tenant_subscriptions', [
            'tenant_id' => $tenant->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'annual',
            'price_cents' => 9900,
        ]);
        $this->assertDatabaseHas('wompi_payment_events', [
            'tenant_id' => $tenant->id,
            'transaction_id' => 'txn-emprendedor-123',
            'payment_link_id' => 'bdfd9af6-ace2-48b1-92e4-07bd182619db',
            'status' => 'processed',
        ]);
    }

    public function test_invalid_wompi_hash_is_rejected_and_not_activated(): void
    {
        config(['services.wompi.api_secret' => 'wompi-secret']);

        $tenant = Tenant::query()->create([
            'slug' => 'cliente-demo',
            'name' => 'Cliente Demo',
        ]);
        $user = User::factory()->create(['email' => 'cliente@stelfaro.test']);
        $user->memberships()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
            'status' => 'active',
            'is_default' => true,
        ]);

        $raw = json_encode($this->approvedPayload(), JSON_UNESCAPED_SLASHES);

        $this->call('POST', '/api/v1/webhooks/wompi', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_WOMPI_HASH' => 'bad-hash',
        ], $raw)->assertUnauthorized()
            ->assertJsonPath('status', 'invalid_hash');

        $this->assertDatabaseMissing('tenant_subscriptions', [
            'tenant_id' => $tenant->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('wompi_payment_events', [
            'transaction_id' => 'txn-123',
            'status' => 'invalid_hash',
            'hash_valid' => false,
        ]);
    }

    public function test_approved_wompi_payment_without_unique_tenant_stays_unresolved(): void
    {
        config(['services.wompi.api_secret' => 'wompi-secret']);

        $this->postWompiWebhook($this->approvedPayload(['cliente' => ['Email' => 'unknown@stelfaro.test']]))
            ->assertAccepted()
            ->assertJsonPath('status', 'unresolved');

        $this->assertDatabaseHas('wompi_payment_events', [
            'transaction_id' => 'txn-123',
            'customer_email' => 'unknown@stelfaro.test',
            'status' => 'unresolved',
            'hash_valid' => true,
        ]);
    }

    public function test_wompi_return_page_is_public_and_informative(): void
    {
        $this->get('/payments/wompi/return?esAprobada=true&idTransaccion=txn-123')
            ->assertOk()
            ->assertSee('Pago en confirmacion')
            ->assertSee('txn-123');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function approvedPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'IdCuenta' => 'account-1',
            'FechaTransaccion' => '2026-06-24T12:00:00-06:00',
            'Monto' => '224.87',
            'ModuloUtilizado' => 'LinkPago',
            'FormaPagoUtilizada' => 'TarjetaCredito',
            'IdTransaccion' => 'txn-123',
            'ResultadoTransaccion' => 'ExitosaAprobada',
            'CodigoAutorizacion' => 'AUTH123',
            'IdIntentoPago' => 'attempt-123',
            'Cantidad' => 1,
            'EsProductiva' => false,
            'Aplicativo' => 'Sandbox',
            'EnlacePago' => [
                'Id' => '33bcab4e-0036-4477-a0a0-326a4a415c31',
                'IdentificadorEnlaceComercio' => null,
                'NombreProducto' => 'Profesional anual',
            ],
            'cliente' => [
                'Nombre' => 'Cliente Demo',
                'Email' => 'cliente@stelfaro.test',
            ],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postWompiWebhook(array $payload)
    {
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $hash = hash_hmac('sha256', $raw, (string) config('services.wompi.api_secret'));

        return $this->call('POST', '/api/v1/webhooks/wompi', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_WOMPI_HASH' => $hash,
        ], $raw);
    }
}

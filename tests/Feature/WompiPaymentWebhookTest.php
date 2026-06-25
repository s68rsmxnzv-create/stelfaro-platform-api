<?php

namespace Tests\Feature;

use App\Models\PlatformApp;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
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

    public function test_sandbox_wompi_webhook_without_hash_can_process_when_app_matches(): void
    {
        config([
            'services.wompi.api_secret' => 'wompi-secret',
            'services.wompi.app_id' => 'app-sandbox-123',
            'services.wompi.payment_links.emprendedor.link_id' => '3930222',
            'services.wompi.payment_links.emprendedor.expected_amount' => '111.87',
        ]);

        PlatformApp::query()->create(['key' => 'facturacion', 'name' => 'Facturacion']);

        $tenant = Tenant::query()->create([
            'slug' => 'cliente-sandbox',
            'name' => 'Cliente Sandbox',
        ]);
        $user = User::factory()->create(['email' => 'sandbox@stelfaro.test']);
        $user->memberships()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
            'status' => 'active',
            'is_default' => true,
        ]);

        $payload = $this->approvedPayload([
            'Monto' => '111.87',
            'IdTransaccion' => 'txn-sandbox-123',
            'IdIntentoPago' => 'attempt-sandbox-123',
            'EsProductiva' => false,
            'Aplicativo' => [
                'Nombre' => 'Servicio Tecnico El Faro',
                'Id' => 'app-sandbox-123',
            ],
            'EnlacePago' => [
                'Id' => 3930222,
                'IdentificadorEnlaceComercio' => 'PLAN EMPRENDEDOR',
                'NombreProducto' => 'Emprendedor anual',
            ],
            'Cliente' => [
                'Nombre' => 'Cliente Sandbox',
                'EMail' => 'sandbox@stelfaro.test',
            ],
        ]);
        unset($payload['cliente']);

        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES);

        $this->call('POST', '/api/v1/webhooks/wompi', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $raw)
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
            'transaction_id' => 'txn-sandbox-123',
            'payment_link_id' => '3930222',
            'customer_email' => 'sandbox@stelfaro.test',
            'status' => 'processed',
            'hash_valid' => false,
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
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Payments/WompiReturn')
                ->where('title', 'Pago recibido')
                ->where('transactionId', 'txn-123')
                ->where('declined', false));
    }

    public function test_wompi_return_page_treats_transaction_redirect_as_received(): void
    {
        $this->get('/payments/wompi/return?identificadorEnlaceComercio=PLAN+EMPRENDEDOR&idTransaccion=txn-456')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Payments/WompiReturn')
                ->where('title', 'Pago recibido')
                ->where('transactionId', 'txn-456')
                ->where('declined', false));
    }

    public function test_wompi_confirmation_page_shows_processed_subscription(): void
    {
        $tenant = Tenant::query()->create([
            'slug' => 'cliente-confirmado',
            'name' => 'Cliente Confirmado',
        ]);
        $plan = SubscriptionPlan::query()->where('key', 'starter')->firstOrFail();
        $subscription = $tenant->subscription()->create([
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'annual',
            'price_cents' => 9900,
            'currency' => 'USD',
            'starts_at' => now(),
            'current_period_ends_at' => now()->addYear(),
        ]);
        $tenant->wompiPaymentEvents()->create([
            'tenant_subscription_id' => $subscription->id,
            'transaction_id' => 'txn-confirmed-123',
            'payment_link_id' => '3930222',
            'commerce_identifier' => 'PLAN EMPRENDEDOR',
            'customer_email' => 'cliente@stelfaro.test',
            'amount' => '111.87',
            'result' => 'ExitosaAprobada',
            'is_productive' => false,
            'hash_valid' => false,
            'status' => 'processed',
            'raw_payload' => [],
            'processed_at' => now(),
        ]);
        $user = User::factory()->create();
        $user->memberships()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
            'status' => 'active',
            'is_default' => true,
        ]);

        $this->actingAs($user)
            ->get('/payments/wompi/confirmation?idTransaccion=txn-confirmed-123')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Payments/WompiConfirmation')
                ->where('transactionId', 'txn-confirmed-123')
                ->where('event.status', 'processed')
                ->where('event.subscription.plan.key', 'starter')
                ->where('event.tenant.name', 'Cliente Confirmado'));
    }

    public function test_public_wompi_confirmation_hides_tenant_and_customer_details(): void
    {
        $tenant = Tenant::query()->create([
            'slug' => 'cliente-privado',
            'name' => 'Cliente Privado',
        ]);
        $plan = SubscriptionPlan::query()->where('key', 'starter')->firstOrFail();
        $subscription = $tenant->subscription()->create([
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'annual',
            'price_cents' => 9900,
            'currency' => 'USD',
            'starts_at' => now(),
            'current_period_ends_at' => now()->addYear(),
        ]);
        $tenant->wompiPaymentEvents()->create([
            'tenant_subscription_id' => $subscription->id,
            'transaction_id' => 'txn-public-123',
            'payment_link_id' => '3930222',
            'commerce_identifier' => 'PLAN EMPRENDEDOR',
            'customer_email' => 'privado@stelfaro.test',
            'amount' => '111.87',
            'result' => 'ExitosaAprobada',
            'is_productive' => false,
            'hash_valid' => false,
            'status' => 'processed',
            'raw_payload' => [],
            'processed_at' => now(),
        ]);

        $this->get('/payments/wompi/confirmation?idTransaccion=txn-public-123')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Payments/WompiConfirmation')
                ->where('event.status', 'processed')
                ->where('event.detailsRestricted', true)
                ->where('event.customerEmail', null)
                ->where('event.tenant', null)
                ->where('event.subscription', null));
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

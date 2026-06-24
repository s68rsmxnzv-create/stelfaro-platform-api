<?php

namespace App\Services\Payments;

use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WompiPaymentEvent;
use App\Services\Platform\TenantSubscriptionManager;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WompiPaymentProcessor
{
    public function __construct(
        private readonly TenantSubscriptionManager $subscriptions,
    ) {}

    /**
     * @return array{status: string, http_status: int, event?: WompiPaymentEvent, message?: string}
     */
    public function handleWebhook(Request $request): array
    {
        $rawBody = $request->getContent();
        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            return [
                'status' => 'invalid_json',
                'http_status' => 400,
                'message' => 'El cuerpo enviado por Wompi no es JSON valido.',
            ];
        }

        $hashValid = $this->hashIsValid($rawBody, (string) $request->headers->get('wompi_hash', ''));
        $event = $this->persistEvent($payload, $request, $hashValid);

        if ($event->status === 'processed') {
            return ['status' => 'already_processed', 'http_status' => 200, 'event' => $event];
        }

        if ($this->apiSecret() === '') {
            $event->forceFill(['status' => 'received_unverified'])->save();

            return [
                'status' => 'received_unverified',
                'http_status' => 202,
                'event' => $event,
                'message' => 'WOMPI_API_SECRET no esta configurado; el pago queda pendiente de revision.',
            ];
        }

        if (! $hashValid) {
            $event->forceFill(['status' => 'invalid_hash'])->save();

            return [
                'status' => 'invalid_hash',
                'http_status' => 401,
                'event' => $event,
                'message' => 'Firma wompi_hash invalida.',
            ];
        }

        if (! $this->isApproved($payload)) {
            $event->forceFill(['status' => 'ignored'])->save();

            return ['status' => 'ignored', 'http_status' => 200, 'event' => $event];
        }

        $paymentOffer = $this->paymentOffer($event);

        if (! $paymentOffer) {
            $event->forceFill(['status' => 'unknown_payment_link'])->save();

            return [
                'status' => 'unknown_payment_link',
                'http_status' => 202,
                'event' => $event,
                'message' => 'Pago recibido, pero el enlace de pago no esta mapeado a un plan.',
            ];
        }

        if (! $this->amountMatches($event, $paymentOffer)) {
            $event->forceFill(['status' => 'amount_mismatch'])->save();

            return [
                'status' => 'amount_mismatch',
                'http_status' => 202,
                'event' => $event,
                'message' => 'El monto recibido no coincide con el monto esperado para el plan.',
            ];
        }

        $tenant = $this->resolveTenant($payload);

        if (! $tenant) {
            $event->forceFill(['status' => 'unresolved'])->save();

            return [
                'status' => 'unresolved',
                'http_status' => 202,
                'event' => $event,
                'message' => 'Pago recibido, pero no se pudo asociar a un tenant unico.',
            ];
        }

        return DB::transaction(function () use ($event, $payload, $tenant, $paymentOffer): array {
            $plan = SubscriptionPlan::query()
                ->where('key', (string) $paymentOffer['plan_key'])
                ->where('status', 'active')
                ->firstOrFail();

            $subscription = $this->subscriptions->applySystem($tenant, $plan, [
                'status' => 'active',
                'billing_cycle' => 'annual',
                'price_cents' => (int) $paymentOffer['price_cents'],
                'currency' => 'USD',
                'starts_at' => now(),
                'current_period_ends_at' => now()->addYear(),
            ], [
                'source' => 'wompi_webhook',
                'wompi_transaction_id' => $event->transaction_id,
                'wompi_payment_attempt_id' => $event->payment_attempt_id,
                'wompi_payment_link_id' => $event->payment_link_id,
                'wompi_customer_email' => $event->customer_email,
                'wompi_amount' => $event->amount,
                'wompi_result' => $event->result,
                'wompi_is_productive' => $event->is_productive,
                'wompi_offer_key' => $paymentOffer['key'],
            ]);

            $event->forceFill([
                'tenant_id' => $tenant->id,
                'tenant_subscription_id' => $subscription->id,
                'status' => 'processed',
                'processed_at' => now(),
                'raw_payload' => $payload,
            ])->save();

            return ['status' => 'processed', 'http_status' => 200, 'event' => $event->fresh()];
        });
    }

    private function apiSecret(): string
    {
        return trim((string) config('services.wompi.api_secret', ''));
    }

    private function hashIsValid(string $rawBody, string $providedHash): bool
    {
        $secret = $this->apiSecret();

        if ($secret === '' || trim($providedHash) === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), Str::lower(trim($providedHash)));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function persistEvent(array $payload, Request $request, bool $hashValid): WompiPaymentEvent
    {
        $attributes = [
            'payment_attempt_id' => $this->payloadString($payload, 'IdIntentoPago'),
            'payment_link_id' => $this->payloadString($payload, 'EnlacePago.Id'),
            'commerce_identifier' => $this->payloadString($payload, 'EnlacePago.IdentificadorEnlaceComercio'),
            'customer_email' => $this->normalizedEmail($this->payloadString($payload, 'cliente.Email')),
            'amount' => $this->decimalAmount(Arr::get($payload, 'Monto')),
            'result' => $this->payloadString($payload, 'ResultadoTransaccion'),
            'is_productive' => $this->nullableBoolean(Arr::get($payload, 'EsProductiva')),
            'hash_valid' => $hashValid,
            'raw_payload' => $payload,
            'headers' => [
                'user_agent' => $request->userAgent(),
                'content_type' => $request->headers->get('content-type'),
                'wompi_hash_present' => $request->headers->has('wompi_hash'),
            ],
        ];

        $transactionId = $this->payloadString($payload, 'IdTransaccion');

        if ($transactionId === null) {
            return WompiPaymentEvent::query()->create([
                ...$attributes,
                'status' => 'received',
            ]);
        }

        $event = WompiPaymentEvent::query()->firstOrNew(['transaction_id' => $transactionId]);

        if ($event->exists && $event->status === 'processed') {
            return $event;
        }

        $event->fill([
            ...$attributes,
            'transaction_id' => $transactionId,
            'status' => 'received',
        ])->save();

        return $event;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isApproved(array $payload): bool
    {
        $result = Str::lower((string) Arr::get($payload, 'ResultadoTransaccion', ''));

        return str_contains($result, 'exitosa') && str_contains($result, 'aprobada');
    }

    /**
     * @param  array{expected_amount?: mixed}  $paymentOffer
     */
    private function amountMatches(WompiPaymentEvent $event, array $paymentOffer): bool
    {
        $expected = $paymentOffer['expected_amount'] ?? null;

        if ($expected === null || $expected === '') {
            return true;
        }

        if ($event->amount === null) {
            return false;
        }

        return abs(((float) $event->amount) - ((float) $expected)) < 0.01;
    }

    /**
     * @return array{key: string, link_id: string|null, plan_key: string, price_cents: int, expected_amount?: mixed}|null
     */
    private function paymentOffer(WompiPaymentEvent $event): ?array
    {
        $paymentLinks = config('services.wompi.payment_links', []);

        if (! is_array($paymentLinks)) {
            return null;
        }

        foreach ($paymentLinks as $key => $paymentLink) {
            if (! is_array($paymentLink)) {
                continue;
            }

            $linkId = $paymentLink['link_id'] ?? null;

            if ($linkId && $event->payment_link_id && hash_equals((string) $linkId, $event->payment_link_id)) {
                return [
                    'key' => (string) $key,
                    'link_id' => (string) $linkId,
                    'plan_key' => (string) ($paymentLink['plan_key'] ?? 'pro'),
                    'price_cents' => (int) ($paymentLink['price_cents'] ?? 0),
                    'expected_amount' => $paymentLink['expected_amount'] ?? null,
                ];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveTenant(array $payload): ?Tenant
    {
        $commerceIdentifier = $this->payloadString($payload, 'EnlacePago.IdentificadorEnlaceComercio');
        $tenant = $this->tenantFromCommerceIdentifier($commerceIdentifier);

        if ($tenant) {
            return $tenant;
        }

        $email = $this->normalizedEmail($this->payloadString($payload, 'cliente.Email'));

        if (! $email) {
            return null;
        }

        $user = User::query()
            ->where('email', $email)
            ->with(['memberships' => fn ($query) => $query
                ->where('status', 'active')
                ->with('tenant')])
            ->first();

        if (! $user) {
            return null;
        }

        $tenants = $user->memberships
            ->pluck('tenant')
            ->filter()
            ->unique('id')
            ->values();

        return $tenants->count() === 1 ? $tenants->first() : null;
    }

    private function tenantFromCommerceIdentifier(?string $commerceIdentifier): ?Tenant
    {
        if (! $commerceIdentifier) {
            return null;
        }

        if (preg_match('/(?:tenant|tenant_id)[:=-](\d+)/i', $commerceIdentifier, $matches) === 1) {
            return Tenant::query()->find((int) $matches[1]);
        }

        if (preg_match('/(?:core_empresa|core_empresa_id)[:=-](\d+)/i', $commerceIdentifier, $matches) === 1) {
            return Tenant::query()
                ->where('metadata->core_empresa_id', (int) $matches[1])
                ->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadString(array $payload, string $key): ?string
    {
        $value = Arr::get($payload, $key);

        if ($value === null || $value === '') {
            return null;
        }

        return trim((string) $value);
    }

    private function normalizedEmail(?string $email): ?string
    {
        if (! $email) {
            return null;
        }

        return Str::lower(trim($email));
    }

    private function decimalAmount(mixed $amount): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        return number_format((float) $amount, 2, '.', '');
    }

    private function nullableBoolean(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }
}

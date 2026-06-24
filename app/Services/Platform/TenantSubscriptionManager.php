<?php

namespace App\Services\Platform;

use App\Models\PlatformApp;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class TenantSubscriptionManager
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function apply(Tenant $tenant, SubscriptionPlan $plan, array $data, User $actor): TenantSubscription
    {
        return $this->applyWithMetadata($tenant, $plan, $data, $actor, [
            'updated_by' => $actor->id,
            'updated_by_email' => $actor->email,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $metadata
     */
    public function applySystem(Tenant $tenant, SubscriptionPlan $plan, array $data, array $metadata = []): TenantSubscription
    {
        return $this->applyWithMetadata($tenant, $plan, $data, null, $metadata);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $metadata
     */
    private function applyWithMetadata(Tenant $tenant, SubscriptionPlan $plan, array $data, ?User $actor, array $metadata): TenantSubscription
    {
        return DB::transaction(function () use ($tenant, $plan, $data, $actor, $metadata): TenantSubscription {
            $status = (string) ($data['status'] ?? 'trialing');
            $durationDays = isset($data['duration_days']) ? (int) $data['duration_days'] : null;
            $startsAt = $data['starts_at'] ?? now();
            $periodEndsAt = $durationDays
                ? CarbonImmutable::parse($startsAt)->addDays($durationDays)
                : ($data['current_period_ends_at'] ?? null);
            $subscription = TenantSubscription::query()->updateOrCreate(
                ['tenant_id' => $tenant->id],
                [
                    'subscription_plan_id' => $plan->id,
                    'status' => $status,
                    'billing_cycle' => (string) ($data['billing_cycle'] ?? $plan->billing_cycle),
                    'price_cents' => (int) ($data['price_cents'] ?? $plan->price_cents),
                    'currency' => (string) ($data['currency'] ?? $plan->currency),
                    'starts_at' => $startsAt,
                    'trial_ends_at' => $data['trial_ends_at'] ?? null,
                    'current_period_ends_at' => $periodEndsAt,
                    'canceled_at' => $status === 'canceled' ? now() : null,
                    'limits' => $data['limits'] ?? $plan->limits,
                    'metadata' => [
                        ...($tenant->subscription?->metadata ?? []),
                        ...$metadata,
                    ],
                ],
            );

            $this->syncAppAccess($tenant, $plan, $status, $actor);

            return $subscription->load('tenant', 'plan');
        });
    }

    public function startProductionTrial(Tenant $tenant, User $actor, int $days = 3): TenantSubscription
    {
        return TenantSubscription::query()->updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'subscription_plan_id' => null,
                'status' => 'trialing',
                'billing_cycle' => 'manual',
                'price_cents' => 0,
                'currency' => 'USD',
                'starts_at' => now(),
                'trial_ends_at' => now()->addDays($days),
                'current_period_ends_at' => now()->addDays($days),
                'canceled_at' => null,
                'limits' => null,
                'metadata' => [
                    'source' => 'production_trial',
                    'trial_days' => $days,
                    'created_by' => $actor->id,
                    'created_by_email' => $actor->email,
                ],
            ],
        );
    }

    private function syncAppAccess(Tenant $tenant, SubscriptionPlan $plan, string $subscriptionStatus, ?User $actor): void
    {
        $includedKeys = collect($plan->included_app_keys ?? [])
            ->map(fn (mixed $key): string => (string) $key)
            ->filter()
            ->unique()
            ->values();

        $apps = PlatformApp::query()
            ->whereIn('key', $includedKeys)
            ->get()
            ->keyBy('key');

        $enabled = in_array($subscriptionStatus, ['trialing', 'active', 'past_due'], true);

        $includedKeys->each(function (string $key) use ($tenant, $apps, $enabled, $actor): void {
            $app = $apps->get($key);

            if (! $app) {
                return;
            }

            $tenant->appAccesses()->updateOrCreate(
                ['platform_app_id' => $app->id],
                [
                    'status' => $enabled ? 'active' : 'suspended',
                    'metadata' => [
                        'source' => 'subscription',
                        ...($actor ? ['updated_by' => $actor->id] : ['updated_by' => 'system']),
                    ],
                ],
            );
        });

        $tenant->appAccesses()
            ->whereHas('app', fn ($query) => $query->whereNotIn('key', $includedKeys))
            ->get()
            ->each(function ($access) use ($actor): void {
                $access->forceFill([
                    'status' => 'inactive',
                    'is_default' => false,
                    'metadata' => [
                        ...($access->metadata ?? []),
                        'disabled_by_subscription_at' => now()->toISOString(),
                        ...($actor ? ['updated_by' => $actor->id] : ['updated_by' => 'system']),
                    ],
                ])->save();
            });
    }
}

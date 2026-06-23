<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Services\Platform\TenantSubscriptionManager;
use App\Services\PlatformAdminAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubscriptionController extends Controller
{
    public function index(Request $request, PlatformAdminAccess $adminAccess): JsonResponse
    {
        $adminAccess->authorize($request->user());

        $plans = SubscriptionPlan::query()
            ->orderByRaw("case when key = 'implementation' then 0 else 1 end")
            ->orderBy('price_cents')
            ->get();

        $tenants = Tenant::query()
            ->with(['subscription.plan', 'appAccesses.app'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'plans' => $plans->map(fn (SubscriptionPlan $plan): array => $this->planPayload($plan))->values(),
            'subscriptions' => $tenants->map(fn (Tenant $tenant): array => $this->tenantSubscriptionPayload($tenant))->values(),
        ]);
    }

    public function update(Request $request, Tenant $tenant, PlatformAdminAccess $adminAccess, TenantSubscriptionManager $manager): JsonResponse
    {
        $adminAccess->authorize($request->user());

        $validated = $request->validate([
            'plan_id' => ['required', 'integer', Rule::exists('subscription_plans', 'id')->where('status', 'active')],
            'status' => ['required', 'string', Rule::in(['trialing', 'active', 'past_due', 'suspended', 'canceled'])],
            'billing_cycle' => ['nullable', 'string', Rule::in(['monthly', 'annual', 'manual'])],
            'price_cents' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'starts_at' => ['nullable', 'date'],
            'trial_ends_at' => ['nullable', 'date'],
            'current_period_ends_at' => ['nullable', 'date'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:3660'],
            'limits' => ['nullable', 'array'],
        ]);

        $plan = SubscriptionPlan::query()->findOrFail((int) $validated['plan_id']);
        $subscription = $manager->apply($tenant, $plan, $validated, $request->user());

        return response()->json([
            'subscription' => $this->subscriptionPayload($subscription),
        ]);
    }

    public function showByCoreEmpresa(Request $request, int $coreEmpresaId, PlatformAdminAccess $adminAccess): JsonResponse
    {
        $adminAccess->authorize($request->user());

        $tenant = $this->tenantByCoreEmpresa($coreEmpresaId);
        $plans = SubscriptionPlan::query()
            ->where('status', 'active')
            ->orderByRaw("case when key = 'implementation' then 0 else 1 end")
            ->orderBy('price_cents')
            ->get();

        return response()->json([
            'plans' => $plans->map(fn (SubscriptionPlan $plan): array => $this->planPayload($plan))->values(),
            'row' => $tenant ? $this->tenantSubscriptionPayload($tenant->load(['subscription.plan', 'appAccesses.app'])) : null,
        ]);
    }

    public function updateByCoreEmpresa(
        Request $request,
        int $coreEmpresaId,
        PlatformAdminAccess $adminAccess,
        TenantSubscriptionManager $manager,
    ): JsonResponse {
        $adminAccess->authorize($request->user());

        $tenant = $this->tenantByCoreEmpresa($coreEmpresaId);
        abort_unless($tenant, 404, 'No existe tenant SaaS para esta empresa fiscal.');

        $validated = $request->validate([
            'plan_id' => ['required', 'integer', Rule::exists('subscription_plans', 'id')->where('status', 'active')],
            'status' => ['required', 'string', Rule::in(['trialing', 'active', 'past_due', 'suspended', 'canceled'])],
            'billing_cycle' => ['nullable', 'string', Rule::in(['monthly', 'annual', 'manual'])],
            'price_cents' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'starts_at' => ['nullable', 'date'],
            'trial_ends_at' => ['nullable', 'date'],
            'current_period_ends_at' => ['nullable', 'date'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:3660'],
            'limits' => ['nullable', 'array'],
        ]);

        $plan = SubscriptionPlan::query()->findOrFail((int) $validated['plan_id']);
        $subscription = $manager->apply($tenant, $plan, $validated, $request->user());

        return response()->json([
            'subscription' => $this->subscriptionPayload($subscription),
        ]);
    }

    private function tenantSubscriptionPayload(Tenant $tenant): array
    {
        return [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status' => $tenant->status,
            ],
            'subscription' => $tenant->subscription ? $this->subscriptionPayload($tenant->subscription) : null,
            'apps' => $tenant->appAccesses
                ->filter(fn ($access): bool => $access->status === 'active')
                ->map(fn ($access): array => [
                    'key' => $access->app?->key,
                    'name' => $access->app?->name,
                    'status' => $access->status,
                    'is_default' => (bool) $access->is_default,
                ])
                ->values(),
        ];
    }

    private function subscriptionPayload(TenantSubscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'tenant_id' => $subscription->tenant_id,
            'plan' => $subscription->plan ? $this->planPayload($subscription->plan) : null,
            'status' => $subscription->status,
            'billing_cycle' => $subscription->billing_cycle,
            'price_cents' => $subscription->price_cents,
            'currency' => $subscription->currency,
            'starts_at' => $subscription->starts_at?->toISOString(),
            'trial_ends_at' => $subscription->trial_ends_at?->toISOString(),
            'current_period_ends_at' => $subscription->current_period_ends_at?->toISOString(),
            'canceled_at' => $subscription->canceled_at?->toISOString(),
            'limits' => $subscription->limits,
        ];
    }

    private function planPayload(SubscriptionPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'key' => $plan->key,
            'name' => $plan->name,
            'description' => $plan->description,
            'price_cents' => $plan->price_cents,
            'currency' => $plan->currency,
            'billing_cycle' => $plan->billing_cycle,
            'included_app_keys' => $plan->included_app_keys ?? [],
            'limits' => $plan->limits ?? [],
            'status' => $plan->status,
        ];
    }

    private function tenantByCoreEmpresa(int $coreEmpresaId): ?Tenant
    {
        return Tenant::query()
            ->where('metadata->core_empresa_id', $coreEmpresaId)
            ->first();
    }
}

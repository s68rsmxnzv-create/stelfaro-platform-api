<?php

namespace App\Http\Controllers;

use App\Models\WompiPaymentEvent;
use App\Support\Platform\PlatformRoles;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WompiPaymentConfirmationController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $transactionId = trim((string) $request->query('idTransaccion', ''));
        $event = $transactionId !== ''
            ? WompiPaymentEvent::query()
                ->where('transaction_id', $transactionId)
                ->with(['tenant', 'subscription.plan'])
                ->first()
            : null;

        $canViewDetails = $event ? $this->canViewDetails($request, $event) : false;

        return Inertia::render('Payments/WompiConfirmation', [
            'transactionId' => $transactionId,
            'event' => $event ? [
                'status' => $event->status,
                'result' => $event->result,
                'amount' => $event->amount,
                'commerceIdentifier' => $event->commerce_identifier,
                'processedAt' => $event->processed_at?->toISOString(),
                'detailsRestricted' => ! $canViewDetails,
                'customerEmail' => $canViewDetails ? $event->customer_email : null,
                'tenant' => $canViewDetails && $event->tenant ? [
                    'id' => $event->tenant->id,
                    'name' => $event->tenant->name,
                ] : null,
                'subscription' => $canViewDetails && $event->subscription ? [
                    'id' => $event->subscription->id,
                    'status' => $event->subscription->status,
                    'billingCycle' => $event->subscription->billing_cycle,
                    'priceCents' => $event->subscription->price_cents,
                    'currency' => $event->subscription->currency,
                    'currentPeriodEndsAt' => $event->subscription->current_period_ends_at?->toISOString(),
                    'plan' => $event->subscription->plan ? [
                        'key' => $event->subscription->plan->key,
                        'name' => $event->subscription->plan->name,
                    ] : null,
                ] : null,
            ] : null,
            'dashboardUrl' => url('/'),
        ]);
    }

    private function canViewDetails(Request $request, WompiPaymentEvent $event): bool
    {
        $user = $request->user();

        if (! $user || ! $event->tenant_id) {
            return false;
        }

        if ($user->platform_role === PlatformRoles::OWNER) {
            return true;
        }

        return $user->memberships()
            ->where('tenant_id', $event->tenant_id)
            ->where('status', 'active')
            ->exists();
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\WompiPaymentEvent;
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

        return Inertia::render('Payments/WompiConfirmation', [
            'transactionId' => $transactionId,
            'event' => $event ? [
                'status' => $event->status,
                'result' => $event->result,
                'amount' => $event->amount,
                'customerEmail' => $event->customer_email,
                'commerceIdentifier' => $event->commerce_identifier,
                'processedAt' => $event->processed_at?->toISOString(),
                'tenant' => $event->tenant ? [
                    'id' => $event->tenant->id,
                    'name' => $event->tenant->name,
                ] : null,
                'subscription' => $event->subscription ? [
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
}

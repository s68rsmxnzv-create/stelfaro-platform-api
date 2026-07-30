<?php

namespace App\Services\Receivables;

use App\Models\ReceivableAccount;
use App\Models\SalesOrder;
use App\Models\WorkshopOrder;

class ReceivableLedger
{
    public function syncWorkshop(WorkshopOrder $order): ReceivableAccount
    {
        $order->loadMissing(['device.customer', 'payments']);
        $charge = round((float) ($order->final_total ?? $order->estimated_total ?? 0), 2);
        $payments = $order->payments->whereNull('voided_at');
        $paid = round((float) $payments->where('kind', 'payment')->sum('amount'), 2);
        $refunded = round((float) $payments->where('kind', 'refund')->sum('amount'), 2);

        return $this->sync(
            tenantId: $order->tenant_id,
            sourceType: 'workshop_order',
            sourceId: $order->id,
            sourceNumber: 'T-'.str_pad((string) $order->ticket_number, 6, '0', STR_PAD_LEFT),
            customerId: $order->device?->customer?->core_customer_id,
            customerName: $order->device?->customer?->name ?? 'Cliente',
            charge: $charge,
            paid: $paid,
            refunded: $refunded,
            cancelled: $order->status === 'cancelled',
            metadata: ['order_status' => $order->status, 'billing_status' => $order->billing_status],
            payments: $payments->map(fn ($payment): array => [
                'id' => $payment->id,
                'kind' => $payment->kind,
                'amount' => (float) $payment->amount,
                'reference' => $payment->reference,
                'notes' => $payment->notes,
                'occurred_at' => $payment->received_at,
            ])->all(),
        );
    }

    public function syncSalesOrder(SalesOrder $order): ReceivableAccount
    {
        $order->loadMissing('payments');
        $payments = $order->payments->whereNull('voided_at');
        $paid = round((float) $payments->where('kind', 'payment')->sum('amount'), 2);
        $refunded = round((float) $payments->where('kind', 'refund')->sum('amount'), 2);

        return $this->sync(
            tenantId: $order->tenant_id,
            sourceType: 'sales_order',
            sourceId: $order->id,
            sourceNumber: 'OT-'.str_pad((string) $order->order_number, 6, '0', STR_PAD_LEFT),
            customerId: $order->core_customer_id,
            customerName: $order->customer_name,
            charge: round((float) $order->total, 2),
            paid: $paid,
            refunded: $refunded,
            cancelled: $order->status === 'cancelled',
            metadata: ['order_status' => $order->status, 'billing_status' => $order->billing_status],
            payments: $payments->map(fn ($payment): array => [
                'id' => $payment->id,
                'kind' => $payment->kind,
                'amount' => (float) $payment->amount,
                'reference' => $payment->reference,
                'notes' => $payment->notes,
                'occurred_at' => $payment->received_at,
            ])->all(),
        );
    }

    /** @param array<int, array<string, mixed>> $payments */
    private function sync(int $tenantId, string $sourceType, int $sourceId, string $sourceNumber, ?int $customerId, string $customerName, float $charge, float $paid, float $refunded, bool $cancelled, array $metadata, array $payments): ReceivableAccount
    {
        $netPaid = round($paid - $refunded, 2);
        $balance = $cancelled ? 0.0 : max(0, round($charge - $netPaid, 2));
        $status = $cancelled ? 'cancelled' : ($balance <= 0 ? 'settled' : ($netPaid > 0 ? 'partial' : 'open'));
        $account = ReceivableAccount::query()->firstOrNew([
            'tenant_id' => $tenantId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ]);
        $recognizedCharge = $cancelled && $account->exists
            ? round((float) $account->original_amount, 2)
            : $charge;
        $account->fill([
            'source_number' => $sourceNumber,
            'core_customer_id' => $customerId,
            'customer_name' => $customerName,
            'original_amount' => $recognizedCharge,
            'paid_amount' => $paid,
            'refunded_amount' => $refunded,
            'balance' => $balance,
            'status' => $status,
            'recognized_at' => $account->recognized_at ?? now(),
            'settled_at' => $status === 'settled' ? ($account->settled_at ?? now()) : null,
            'cancelled_at' => $cancelled ? ($account->cancelled_at ?? now()) : null,
            'metadata' => $metadata,
        ])->save();

        $account->entries()->updateOrCreate(
            ['entry_type' => 'charge', 'source_type' => $sourceType, 'source_id' => $sourceId],
            ['tenant_id' => $tenantId, 'amount' => $recognizedCharge, 'reference' => $sourceNumber, 'notes' => 'Valor acordado de la orden.', 'occurred_at' => $account->created_at ?? now()],
        );
        foreach ($payments as $payment) {
            $account->entries()->updateOrCreate(
                ['entry_type' => $payment['kind'], 'source_type' => $sourceType.'_payment', 'source_id' => $payment['id']],
                ['tenant_id' => $tenantId, 'amount' => $payment['amount'], 'reference' => $payment['reference'], 'notes' => $payment['notes'], 'occurred_at' => $payment['occurred_at'] ?? now()],
            );
        }
        if ($cancelled) {
            $account->entries()->updateOrCreate(
                ['entry_type' => 'cancellation', 'source_type' => $sourceType, 'source_id' => $sourceId],
                ['tenant_id' => $tenantId, 'amount' => max(0, round($recognizedCharge - $netPaid, 2)), 'reference' => $sourceNumber, 'notes' => 'Saldo anulado por cancelación de la orden.', 'occurred_at' => now()],
            );
        }

        return $account->refresh('entries');
    }
}

<?php

namespace App\Observers;

use App\Models\SalesOrderPayment;
use App\Services\Receivables\ReceivableLedger;

class SalesOrderPaymentObserver
{
    public function saved(SalesOrderPayment $payment): void
    {
        app(ReceivableLedger::class)->syncSalesOrder($payment->order);
    }
}

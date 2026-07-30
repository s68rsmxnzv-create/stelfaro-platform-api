<?php

namespace App\Observers;

use App\Models\WorkshopOrderPayment;
use App\Services\Receivables\ReceivableLedger;

class WorkshopOrderPaymentObserver
{
    public function saved(WorkshopOrderPayment $payment): void
    {
        app(ReceivableLedger::class)->syncWorkshop($payment->order);
    }
}

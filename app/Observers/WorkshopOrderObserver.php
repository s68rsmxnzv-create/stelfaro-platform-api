<?php

namespace App\Observers;

use App\Models\WorkshopOrder;
use App\Services\Receivables\ReceivableLedger;

class WorkshopOrderObserver
{
    public function saved(WorkshopOrder $order): void
    {
        if ($order->estimated_total !== null || $order->final_total !== null) {
            app(ReceivableLedger::class)->syncWorkshop($order);
        }
    }
}

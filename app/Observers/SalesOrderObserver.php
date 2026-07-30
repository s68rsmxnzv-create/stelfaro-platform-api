<?php

namespace App\Observers;

use App\Models\SalesOrder;
use App\Services\Receivables\ReceivableLedger;

class SalesOrderObserver
{
    public function saved(SalesOrder $order): void
    {
        app(ReceivableLedger::class)->syncSalesOrder($order);
    }
}

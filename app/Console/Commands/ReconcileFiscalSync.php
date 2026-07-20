<?php

namespace App\Console\Commands;

use App\Services\FiscalSyncService;
use Illuminate\Console\Command;

class ReconcileFiscalSync extends Command
{
    protected $signature = 'fiscal-sync:reconcile {--limit=50}';

    protected $description = 'Recupera sincronizaciones pendientes entre el core fiscal, inventario y ventas';

    public function handle(FiscalSyncService $sync): int
    {
        $stats = $sync->reconcile((int) $this->option('limit'));
        $this->info(sprintf(
            'Sincronización fiscal: %d completadas, %d pendientes, %d con reintento.',
            $stats['completed'],
            $stats['pending'],
            $stats['failed'],
        ));

        return self::SUCCESS;
    }
}

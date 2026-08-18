<?php

namespace App\Console\Commands;

use App\Services\DteStuckNotificationGenerator;
use Illuminate\Console\Command;

class GenerateDteStuckNotifications extends Command
{
    protected $signature = 'dte-stuck-notifications:generate';

    protected $description = 'Notifica a los usuarios del tenant cuando un DTE quedó atascado y agotó los reintentos automáticos en dte-core';

    public function handle(DteStuckNotificationGenerator $generator): int
    {
        $created = $generator->generate();
        $this->info("Notificaciones creadas: {$created}");

        return self::SUCCESS;
    }
}

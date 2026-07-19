<?php

namespace App\Console\Commands;

use App\Services\TaxDeadlineNotificationGenerator;
use Illuminate\Console\Command;

class GenerateTaxDeadlineNotifications extends Command
{
    protected $signature = 'tax-notifications:generate';

    protected $description = 'Genera recordatorios internos para los próximos vencimientos tributarios';

    public function handle(TaxDeadlineNotificationGenerator $generator): int
    {
        $created = $generator->generate();
        $this->info("Notificaciones creadas: {$created}");

        return self::SUCCESS;
    }
}

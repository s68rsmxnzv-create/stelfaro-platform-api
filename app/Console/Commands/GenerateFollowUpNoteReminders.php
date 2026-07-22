<?php

namespace App\Console\Commands;

use App\Services\FollowUpNoteReminderGenerator;
use Illuminate\Console\Command;

class GenerateFollowUpNoteReminders extends Command
{
    protected $signature = 'follow-up-notes:remind';

    protected $description = 'Genera recordatorios internos para notas de seguimiento pendientes';

    public function handle(FollowUpNoteReminderGenerator $generator): int
    {
        $this->info('Recordatorios creados: '.$generator->generate());

        return self::SUCCESS;
    }
}

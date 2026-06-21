<?php

namespace App\Console\Commands;

use App\Services\Platform\UserInvitationService;
use Illuminate\Console\Command;

class ExpireUserInvitationsCommand extends Command
{
    protected $signature = 'platform:invitations:expire';

    protected $description = 'Expire pending platform user invitations.';

    public function handle(UserInvitationService $invitations): int
    {
        $expired = $invitations->expirePending();

        $this->info("Expired {$expired} invitation(s).");

        return self::SUCCESS;
    }
}

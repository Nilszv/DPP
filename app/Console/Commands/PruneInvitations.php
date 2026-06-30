<?php

namespace App\Console\Commands;

use App\Models\Invitation;
use Illuminate\Console\Command;

/** Deletes expired, unaccepted invitations so they stop lingering. Scheduled daily. */
class PruneInvitations extends Command
{
    protected $signature = 'invitations:prune';

    protected $description = 'Delete expired, unaccepted team invitations';

    public function handle(): int
    {
        $deleted = Invitation::whereNull('accepted_at')
            ->where('expires_at', '<', now())
            ->delete();

        $this->info("Pruned {$deleted} expired invitation(s).");

        return self::SUCCESS;
    }
}

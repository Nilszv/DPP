<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/** Promote (or demote) a user to platform super-admin: php artisan admin:grant you@example.com */
class GrantAdmin extends Command
{
    protected $signature = 'admin:grant {email} {--revoke : Remove admin instead of granting}';

    protected $description = 'Grant or revoke platform super-admin for a user by email';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("No user with email {$email}. They must sign in once first.");

            return self::FAILURE;
        }

        $user->update(['is_admin' => ! $this->option('revoke')]);
        $this->info($this->option('revoke')
            ? "Revoked admin from {$email}."
            : "Granted admin to {$email}.");

        return self::SUCCESS;
    }
}

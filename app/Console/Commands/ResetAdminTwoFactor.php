<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Console\Command;

/** Operator escape hatch for a locked-out admin: php artisan admin:reset-2fa you@example.com */
class ResetAdminTwoFactor extends Command
{
    protected $signature = 'admin:reset-2fa {email}';

    protected $description = 'Clear a super-admin\'s 2FA setup, forcing fresh setup on next login';

    public function handle(TwoFactorService $twoFactor): int
    {
        $email = strtolower(trim($this->argument('email')));
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("No user with email {$email}.");

            return self::FAILURE;
        }

        if (! $user->isAdmin()) {
            $this->error("{$email} is not an admin; nothing to reset.");

            return self::FAILURE;
        }

        $twoFactor->reset($user);
        $this->info("Cleared 2FA for {$email}. They will be prompted to set it up again on next login.");

        return self::SUCCESS;
    }
}

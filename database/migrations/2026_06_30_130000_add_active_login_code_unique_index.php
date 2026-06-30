<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hard DB invariant: at most one ACTIVE (unconsumed) login code per email at a time.
 * Backs the advisory lock in LoginCodeService::issue() so the "one live code" rule holds
 * even if some future code path forgets to take the lock.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX login_codes_one_active_per_email
             ON login_codes (email) WHERE consumed_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS login_codes_one_active_per_email');
    }
};

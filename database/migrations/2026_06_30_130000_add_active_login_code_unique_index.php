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
        // Make the migration safe on any existing data: if duplicate active codes already
        // exist for an email, consume all but the newest so the unique index can be built.
        DB::statement(
            'UPDATE login_codes SET consumed_at = now()
             WHERE consumed_at IS NULL
               AND id NOT IN (
                   SELECT DISTINCT ON (email) id FROM login_codes
                   WHERE consumed_at IS NULL
                   ORDER BY email, created_at DESC
               )'
        );

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

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill team_quota on existing plan rows. The column was added nullable, and NULL means
 * "unlimited" -- so a DB that was migrated before the seeder ran would give free/medium
 * unlimited seats. Set the known defaults (commercial stays NULL = unlimited). Idempotent:
 * only touches rows still NULL, so an admin who intentionally set a value is never clobbered.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("UPDATE plans SET team_quota = 1 WHERE key = 'free' AND team_quota IS NULL");
        DB::statement("UPDATE plans SET team_quota = 3 WHERE key = 'medium' AND team_quota IS NULL");
    }

    public function down(): void
    {
        // No-op: leave the backfilled values in place.
    }
};

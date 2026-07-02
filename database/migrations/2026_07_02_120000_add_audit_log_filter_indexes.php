<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indexes backing the /admin/audit browser filters. Created on the partitioned parent, so
 * Postgres propagates them to every existing and future partition (incl. partitions:ensure).
 * (organization_id, ts DESC) already exists from the original table migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX audit_log_action_ts ON audit_log (action, ts DESC)');
        DB::statement('CREATE INDEX audit_log_actor_ts ON audit_log (actor_id, ts DESC)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS audit_log_action_ts');
        DB::statement('DROP INDEX IF EXISTS audit_log_actor_ts');
    }
};

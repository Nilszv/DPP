<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Auth-hardening DB constraints (from code review):
 *  - Emails become `citext` so uniqueness/lookups are case-insensitive at the DB level,
 *    not only via app-side lowercasing.
 *  - users.current_organization_id gets a real FK to organizations (set null on delete),
 *    so it can never point at a non-existent org.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Case-insensitive emails at the database level (citext extension already enabled).
        DB::statement('ALTER TABLE users ALTER COLUMN email TYPE citext');
        DB::statement('ALTER TABLE login_codes ALTER COLUMN email TYPE citext');

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('current_organization_id')
                ->references('id')->on('organizations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['current_organization_id']);
        });

        DB::statement('ALTER TABLE users ALTER COLUMN email TYPE varchar(255)');
        DB::statement('ALTER TABLE login_codes ALTER COLUMN email TYPE varchar(255)');
    }
};

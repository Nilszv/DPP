<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB-level backstop for the publish/discard race (external review): current_version_id had no
 * FK, so deleting the version a passport is serving would silently leave a dangling pointer.
 * RESTRICT (not cascade/null): nothing legitimate ever deletes a version that is live -- the
 * discard path only deletes unlocked non-current drafts, and passport/org deletion removes the
 * passport head row first (so its versions no longer have a referencing row when the
 * passport_id cascade reaches them). If this constraint ever fires, it caught a bug.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('passports', function (Blueprint $table) {
            $table->foreign('current_version_id')
                ->references('id')->on('passport_versions')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('passports', function (Blueprint $table) {
            $table->dropForeign(['current_version_id']);
        });
    }
};

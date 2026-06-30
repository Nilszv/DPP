<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Team management: per-plan seat limits, a per-org override (custom deals), and email
 * invitations. A seat = an accepted member or an outstanding (unaccepted) invitation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->integer('team_quota')->nullable();   // null = unlimited seats
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->integer('team_quota_override')->nullable();
        });

        Schema::create('invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('email');                     // stored lowercase
            $table->string('role')->default('viewer');   // owner | admin | editor | viewer
            $table->string('token')->unique();
            $table->uuid('invited_by')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->index('email');
        });

        // At most one outstanding (unaccepted) invitation per email per org.
        DB::statement(
            'CREATE UNIQUE INDEX invitations_one_pending_per_email
             ON invitations (organization_id, email) WHERE accepted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('team_quota_override');
        });
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('team_quota');
        });
    }
};

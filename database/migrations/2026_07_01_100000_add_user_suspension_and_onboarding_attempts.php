<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User-level suspension (distinct from org suspension) plus a counter of blocked duplicate
 * onboarding attempts. When a registration duplicates an existing account too many times,
 * the email is suspended until support resolves it. suspension_reason is admin-only detail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable()->after('current_organization_id');
            $table->text('suspension_reason')->nullable()->after('suspended_at');
            $table->unsignedInteger('duplicate_onboarding_attempts')->default(0)->after('suspension_reason');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['suspended_at', 'suspension_reason', 'duplicate_onboarding_attempts']);
        });
    }
};

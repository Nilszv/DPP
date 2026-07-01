<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TOTP two-factor auth, required for admin users. two_factor_secret is encrypted (must be
 * decryptable to compute/verify codes); two_factor_recovery_codes stores individually
 * bcrypt-hashed single-use codes (same pattern as login_codes.code_hash), not the secret itself,
 * so no encryption is needed there. two_factor_confirmed_at null means setup is not yet complete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('duplicate_onboarding_attempts');
            $table->json('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
    }
};

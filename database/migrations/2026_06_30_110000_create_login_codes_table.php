<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Passwordless auth. A short-lived, single-use code is emailed to verify ownership of an
 * address. The code itself is stored HASHED (never plaintext). Brute force is bounded by
 * a short expiry, an attempts cap, and request rate limiting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email')->index();
            $table->string('code_hash');                 // bcrypt hash of the 6-digit code
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable(); // set once used; single-use
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_codes');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One durable, revocable secret per (passport, tiered audience) -- repairer/recycler/authority
 * links may be printed in physical service manuals for years, so unlike Laravel signed URLs
 * this must survive APP_KEY rotation and be individually regenerable if a link leaks.
 * Consumer and full audiences never get a row here: consumer is already reachable via the
 * passport's own public_id, and full is internal/non-distributed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passport_access_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('passport_id');
            $table->string('audience'); // repairer | recycler | authority
            $table->string('token')->unique();
            $table->timestamps();

            $table->foreign('passport_id')->references('id')->on('passports')->cascadeOnDelete();
            $table->unique(['passport_id', 'audience']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passport_access_tokens');
    }
};

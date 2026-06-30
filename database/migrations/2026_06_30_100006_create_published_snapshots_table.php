<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SYSTEM OF DELIVERY. Pre-rendered, per-audience, per-locale view of a published passport.
 * Built by a queued job on publish/update. The resolver serves a scan by reading ONE row
 * here (single key lookup) -- never a live join. Redis/CDN slot in front of this later with
 * no rewrite. This is the table that keeps reads O(1) at any scale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('published_snapshots', function (Blueprint $table) {
            $table->uuid('passport_id');
            $table->string('audience');     // consumer | repairer | recycler | authority | full
            $table->string('locale', 8);
            $table->jsonb('rendered');      // pre-filtered (by access_map) + pre-translated body
            $table->string('etag', 64);     // for CDN/HTTP caching + purge
            $table->timestamp('updated_at')->useCurrent();

            $table->primary(['passport_id', 'audience', 'locale']);
            $table->foreign('passport_id')->references('id')->on('passports')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('published_snapshots');
    }
};

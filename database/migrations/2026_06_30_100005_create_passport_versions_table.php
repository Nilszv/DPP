<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only version history (regulatory: versioning + tamper-evidence + locked master data).
 * - data: the JSON-LD passport body for this version.
 * - content_hash: sha256 of CANONICAL JSON (sorted keys, compact) -> reproducible/verifiable.
 * - locked: true once the version is published; the body is never UPDATEd, corrections
 *   create a NEW version. GDPR note: keep personal data OUT of locked versions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passport_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('passport_id');
            $table->unsignedInteger('version_no');
            $table->jsonb('data');
            $table->string('content_hash', 64);          // sha256 hex of canonical JSON
            $table->uuid('created_by')->nullable();
            $table->boolean('locked')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('passport_id')->references('id')->on('passports')->cascadeOnDelete();
            $table->unique(['passport_id', 'version_no']);
            $table->index(['passport_id', 'version_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passport_versions');
    }
};

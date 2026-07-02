<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual per-locale field-VALUE translations (decided 2026-07-02: manufacturers translate
 * their own regulated content; machine translation may later only pre-fill drafts for human
 * review). Shape: {locale: {field_key: value}}. The base `data` stays the as-entered source
 * record and the fallback for anything untranslated -- so null here means exactly the
 * pre-feature behavior. Lives on the version: translations are part of the locked, versioned
 * record and travel through corrections like the rest of the master data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('passport_versions', function (Blueprint $table) {
            $table->jsonb('translations')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('passport_versions', function (Blueprint $table) {
            $table->dropColumn('translations');
        });
    }
};

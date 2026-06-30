<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The passport "system of record" head row. The actual JSON-LD body lives in
 * passport_versions (append-only). This row holds identity, status, lifecycle dates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('product_id');

            // --- Identity / carrier ---
            // Opaque URL id, always present -> /p/{public_id}. Enumeration-resistant.
            $table->uuid('public_id')->unique();
            $table->string('identifier_scheme')->default('self'); // self | gs1 | iec61406 | did
            $table->string('gtin')->nullable();                   // GS1 path: /01/{gtin}/21/{serial}
            $table->string('serial')->nullable();
            $table->string('batch')->nullable();

            // --- Lifecycle ---
            $table->string('status')->default('draft');           // draft | published | archived
            $table->uuid('current_version_id')->nullable();       // points at locked published version
            $table->string('default_locale', 8)->default('lv');
            $table->timestamp('published_at')->nullable();
            $table->date('retention_until')->nullable();          // published_at + lifetime + 10y

            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();

            $table->index('organization_id');
            $table->index('status');
            $table->index('retention_until');   // archival sweep
        });

        // Partial unique index: GS1 (gtin, serial) must be unique ONLY among gs1-scheme rows.
        // Laravel's Blueprint can't express a partial index, so raw SQL (Postgres feature).
        DB::statement(
            "CREATE UNIQUE INDEX passports_gs1_unique ON passports (gtin, serial)
             WHERE identifier_scheme = 'gs1'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('passports');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-organization billing overrides for bespoke deals (e.g. a commercial client with a
 * negotiated monthly/yearly price). null = fall back to the plan. Quota override already
 * exists; this adds price + interval so a custom price can be set at the org level.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->decimal('price_override', 8, 2)->nullable();   // custom price for this org
            $table->string('interval_override')->nullable();        // month | year | null
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['price_override', 'interval_override']);
        });
    }
};

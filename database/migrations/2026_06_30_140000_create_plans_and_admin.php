<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform back-office foundations:
 *  - users.is_admin: platform super-admin (distinct from in-org roles).
 *  - plans: DB-driven plan catalogue so prices/quotas are editable from the admin UI
 *    (replaces the static config as the source of truth; config remains a fallback/seed).
 *  - organizations.published_quota_override: per-org custom quota for bespoke deals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->index();
        });

        Schema::table('organizations', function (Blueprint $table) {
            // null = use the plan's quota; set = custom override for this org.
            $table->integer('published_quota_override')->nullable();
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key')->unique();              // free | medium | commercial | custom-*
            $table->string('name');
            $table->decimal('price', 8, 2)->nullable();   // null = custom/contact
            $table->string('interval')->nullable();       // month | year | custom | null
            $table->integer('published_quota')->nullable(); // null = unlimited
            $table->boolean('is_public')->default(true);  // shown on the tenant plan page
            $table->boolean('active')->default(true);
            $table->string('stripe_price_id')->nullable(); // filled when Stripe is wired
            $table->integer('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('published_quota_override');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};

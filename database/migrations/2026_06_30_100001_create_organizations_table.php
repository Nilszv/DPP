<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenancy root. An Organization is the billable tenant (scope §2.1).
 * Every tenant-owned table carries organization_id and is row-isolated via a global scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            // Quota/feature gating now; Stripe subscription wires onto this in Slice 2.
            $table->string('plan')->default('free');        // free | medium | commercial
            $table->string('status')->default('active');    // active | suspended
            $table->string('vat_id')->nullable();           // EU VAT (reverse charge later)
            $table->string('custom_domain')->nullable();    // commercial-tier resolver domain
            $table->timestamps();

            $table->index('plan');
        });

        // User <-> Organization membership with in-org role. A user can belong to many orgs.
        Schema::create('organization_user', function (Blueprint $table) {
            $table->uuid('organization_id');
            $table->uuid('user_id');
            $table->string('role')->default('viewer');      // owner | admin | editor | viewer
            $table->timestamps();

            $table->primary(['organization_id', 'user_id']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_user');
        Schema::dropIfExists('organizations');
    }
};

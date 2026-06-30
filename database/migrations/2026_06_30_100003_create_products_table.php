<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Product is the thing a passport describes. One product can have many passports
 * (e.g. per batch/serial). Tenant-scoped by organization_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('template_id');
            $table->string('name');
            $table->string('category');               // mirrors template category for fast filtering
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('template_id')->references('id')->on('templates')->restrictOnDelete();
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};

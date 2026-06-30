<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Templates are DATA, not code. Each delegated act / product category defines different
 * fields, so the passport body is JSONB validated against a template's field_schema.
 * Adding a new category later = inserting a row, no code change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Global templates have null organization_id; commercial tier may add custom ones.
            $table->uuid('organization_id')->nullable();
            $table->string('key')->unique();          // e.g. 'generic', 'textiles'
            $table->string('name');
            $table->string('category');               // drives delegated-act field set
            $table->jsonb('field_schema');            // [{key,label,type,required,tier}, ...]
            $table->jsonb('access_map');              // field -> [consumer,repairer,recycler,authority]
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};

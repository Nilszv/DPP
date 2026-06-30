<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * First-run onboarding + legal acceptance:
 *  - organizations gain a company profile (legal name, address, country for tax, contact
 *    person) and onboarding_completed_at to gate the app until the profile + legal terms are
 *    accepted.
 *  - legal_documents: admin-editable, versioned policy texts (e.g. the registration policy).
 *  - legal_acceptances: who accepted which document version, when (audit for the 10-year duty).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('legal_name')->nullable();
            $table->string('registration_number')->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country', 2)->nullable();        // ISO-3166-1 alpha-2 (drives tax %)
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->timestamp('onboarding_completed_at')->nullable();
        });

        Schema::create('legal_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key')->unique();                 // registration_policy | terms | ...
            $table->string('title');
            $table->text('body');
            $table->unsignedInteger('version')->default(1);  // bumped when the body changes
            $table->boolean('requires_acceptance')->default(true);
            $table->timestamps();
        });

        Schema::create('legal_acceptances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('user_id');
            $table->string('document_key');
            $table->unsignedInteger('document_version');
            $table->string('ip_hash')->nullable();
            $table->timestamp('accepted_at')->useCurrent();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->index(['organization_id', 'document_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_acceptances');
        Schema::dropIfExists('legal_documents');
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn([
                'legal_name', 'registration_number', 'address_line1', 'address_line2',
                'city', 'postal_code', 'country', 'contact_name', 'contact_email',
                'contact_phone', 'onboarding_completed_at',
            ]);
        });
    }
};

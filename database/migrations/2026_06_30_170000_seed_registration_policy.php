<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ensures the registration policy exists at the DB level, independent of seeders, so a
 * deployment that migrates but does not run LegalDocumentSeeder still has a required policy
 * (onboarding must never complete without an acceptance). Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('legal_documents')->where('key', 'registration_policy')->exists();
        if ($exists) {
            return;
        }

        DB::table('legal_documents')->insert([
            'id' => (string) Str::uuid(),
            'key' => 'registration_policy',
            'title' => 'Registration policy and terms',
            'version' => 1,
            'requires_acceptance' => true,
            'body' => <<<'TXT'
            Please read these terms carefully before completing registration.

            1. Digital Product Passports you publish must remain accessible for the product
               lifetime plus at least 10 years. By publishing a passport you accept that this
               is a long-term hosting commitment.

            2. Your subscription funds the hosting of your published passports. If you stop
               paying, published passports do not disappear immediately; arrangements for their
               continued hosting must be made with us (see "Contact sales").

            3. The economic operator remains accountable for the accuracy and completeness of
               the passport data.

            4. You agree to provide accurate company information, including your country, which
               determines the applicable tax rate.

            This is placeholder text. The policy maker will replace it with the final policy
            from the admin back-office.
            TXT,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Leave the document in place on rollback; removing it could orphan acceptances.
    }
};

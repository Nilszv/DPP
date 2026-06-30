<?php

namespace Database\Seeders;

use App\Models\LegalDocument;
use Illuminate\Database\Seeder;

/** Seeds the default registration policy (idempotent). Admins edit the text in the back-office. */
class LegalDocumentSeeder extends Seeder
{
    public function run(): void
    {
        LegalDocument::firstOrCreate(
            ['key' => 'registration_policy'],
            [
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
            ]
        );
    }
}

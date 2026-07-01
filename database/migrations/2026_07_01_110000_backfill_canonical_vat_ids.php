<?php

use App\Models\Organization;
use App\Support\VatNumber;
use Illuminate\Database\Migrations\Migration;

/**
 * Backfill: canonicalize any VAT numbers stored before server-side canonicalization existed,
 * so the duplicate-registration guard compares like-for-like on legacy rows too. Idempotent
 * (canonicalizing an already-canonical value is a no-op). Safe on a fresh DB (no rows).
 */
return new class extends Migration
{
    public function up(): void
    {
        Organization::query()
            ->whereNotNull('vat_id')
            ->chunkById(200, function ($orgs) {
                foreach ($orgs as $org) {
                    $canonical = VatNumber::canonical($org->country, $org->vat_id);

                    if ($canonical !== $org->vat_id) {
                        $org->vat_id = $canonical;
                        $org->saveQuietly();
                    }
                }
            });
    }

    public function down(): void
    {
        // Not reversible: the original, non-canonical formatting is not retained.
    }
};

<?php

use App\Models\Passport;
use App\Models\PassportAccessToken;
use App\Services\SnapshotBuilder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;

/**
 * Backfill: passports published before tiered views existed never got repairer/recycler/
 * authority snapshot rows or access tokens. Idempotent -- rebuilding a snapshot is a plain
 * upsert, and token issuance uses firstOrCreate (not issue()/overwrite) so re-running this
 * never rotates an already-backfilled, already-shared token out from under someone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Passport::where('status', 'published')
            ->chunkById(200, function ($passports) {
                foreach ($passports as $passport) {
                    $version = $passport->currentVersion;
                    $template = $passport->product->template;

                    app(SnapshotBuilder::class)->build($passport, $version, $template);

                    foreach (['repairer', 'recycler', 'authority'] as $audience) {
                        PassportAccessToken::firstOrCreate(
                            ['passport_id' => $passport->id, 'audience' => $audience],
                            ['token' => Str::random(48)],
                        );
                    }
                }
            });
    }

    public function down(): void
    {
        // Not reversible: tokens already distributed cannot be un-issued.
    }
};

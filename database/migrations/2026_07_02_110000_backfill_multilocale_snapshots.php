<?php

use App\Models\Passport;
use App\Services\SnapshotBuilder;
use Database\Seeders\TemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Backfill for the public-layer i18n: passports published before multi-locale snapshots
 * existed only have rows for their default_locale. Re-seeds the global generic template
 * first (its field_schema gains the per-locale 'labels' maps -- guaranteed by migration,
 * not just the seeder, same precedent as the registration policy), then rebuilds every
 * published passport's snapshots so all configured locales exist. Idempotent: the seeder
 * is an updateOrCreate on the template key, and snapshot builds are composite-key upserts.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new TemplateSeeder)->run();

        Passport::where('status', 'published')
            ->chunkById(200, function ($passports) {
                foreach ($passports as $passport) {
                    app(SnapshotBuilder::class)->build(
                        $passport,
                        $passport->currentVersion,
                        $passport->product->template,
                    );
                }
            });
    }

    public function down(): void
    {
        // Not reversible (and harmless to keep): extra locale rows are simply never chosen
        // once the locale disappears from config('dpp.locales').
    }
};

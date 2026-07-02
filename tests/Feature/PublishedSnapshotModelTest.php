<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Passport;
use App\Models\Product;
use App\Models\PublishedSnapshot;
use App\Models\Template;
use App\Services\PassportPublisher;
use Database\Seeders\TemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression coverage for a data-corrupting bug: PublishedSnapshot has no single-column
 * primary key (its real key is the composite passport_id+audience+locale), and Eloquent's
 * default save()/fresh() build their WHERE from a single $primaryKey. Left unguarded, that
 * produces an unconstrained UPDATE that silently overwrites every row in the table.
 */
class PublishedSnapshotModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_one_snapshot_row_does_not_overwrite_other_rows(): void
    {
        $this->seed(TemplateSeeder::class);
        $org = Organization::create([
            'name' => 'Acme', 'slug' => 'acme-'.Str::lower(Str::random(6)),
            'plan' => 'commercial', 'status' => 'active', 'onboarding_completed_at' => now(),
        ]);

        $passportA = $this->publishedPassport($org, 'Product A');
        $passportB = $this->publishedPassport($org, 'Product B');

        $this->assertSame(20, PublishedSnapshot::count()); // 2 passports x 5 audiences x 2 locales

        $target = PublishedSnapshot::where('passport_id', $passportA->id)->where('audience', 'consumer')->first();
        $target->etag = 'mutated-etag';
        $target->save();

        $this->assertSame(1, PublishedSnapshot::where('etag', 'mutated-etag')->count());
        $this->assertSame(20, PublishedSnapshot::count());

        // Every row except the one mutated (same passport+audience+locale composite key) must
        // be untouched -- including passport A's other audiences AND the other locale of the
        // same consumer audience.
        $untouched = PublishedSnapshot::whereNot(function ($q) use ($target) {
            $q->where('passport_id', $target->passport_id)
                ->where('audience', $target->audience)
                ->where('locale', $target->locale);
        })->get();

        $this->assertCount(19, $untouched);
        $this->assertTrue($untouched->every(fn ($s) => $s->etag !== 'mutated-etag'));
    }

    private function publishedPassport(Organization $org, string $name): Passport
    {
        $template = Template::where('key', 'generic')->first();
        $product = Product::create([
            'organization_id' => $org->id, 'template_id' => $template->id,
            'name' => $name, 'category' => 'generic',
        ]);
        $passport = Passport::create([
            'organization_id' => $org->id, 'product_id' => $product->id,
            'public_id' => (string) Str::uuid(), 'identifier_scheme' => 'self',
            'status' => 'draft', 'default_locale' => 'lv',
        ]);
        $passport->versions()->create([
            'version_no' => 1,
            'data' => ['product_name' => $name, 'manufacturer' => 'Acme'],
            'content_hash' => 'pending', 'locked' => false,
        ]);

        app(PassportPublisher::class)->publish($passport);

        return $passport->refresh();
    }
}

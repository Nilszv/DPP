<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Passport;
use App\Models\Product;
use App\Models\PublishedSnapshot;
use App\Models\Template;
use App\Models\User;
use App\Services\PassportPublisher;
use Database\Seeders\TemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Public-layer i18n: snapshots are pre-built per locale x audience, the resolver negotiates
 * the language (?lang= wins, then Accept-Language, then the passport's default), field labels
 * come from the template's per-locale maps, and the page chrome is translated. Field VALUES
 * are always served exactly as the manufacturer entered them.
 */
class PublicI18nTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TemplateSeeder::class);
        $this->org = Organization::create([
            'name' => 'Acme', 'slug' => 'acme-'.Str::lower(Str::random(6)),
            'plan' => 'free', 'status' => 'active', 'onboarding_completed_at' => now(),
        ]);
    }

    public function test_publishing_builds_a_snapshot_row_per_locale_and_audience(): void
    {
        $passport = $this->publishedPassport();

        foreach (['lv', 'en'] as $locale) {
            foreach (config('dpp.audiences') as $audience) {
                $this->assertTrue(
                    PublishedSnapshot::where('passport_id', $passport->id)
                        ->where('audience', $audience)->where('locale', $locale)->exists(),
                    "Missing snapshot row for {$audience}/{$locale}."
                );
            }
        }
    }

    public function test_labels_are_localized_but_values_are_served_as_entered(): void
    {
        $passport = $this->publishedPassport();

        $this->get("/p/{$passport->public_id}?lang=lv")
            ->assertOk()
            ->assertSee('Ražotājs')                 // localized label
            ->assertSee('Acme Textiles')            // value exactly as entered
            ->assertSee('Digitālā produkta pase');  // translated chrome

        $this->get("/p/{$passport->public_id}?lang=en")
            ->assertOk()
            ->assertSee('Manufacturer')
            ->assertSee('Acme Textiles')
            ->assertSee('Digital Product Passport');
    }

    public function test_accept_language_header_negotiates_the_locale(): void
    {
        $passport = $this->publishedPassport();

        $this->get("/p/{$passport->public_id}", ['Accept-Language' => 'en-GB,en;q=0.9'])
            ->assertOk()->assertSee('Manufacturer');

        $this->get("/p/{$passport->public_id}", ['Accept-Language' => 'lv-LV,lv;q=0.9,en;q=0.5'])
            ->assertOk()->assertSee('Ražotājs');
    }

    public function test_unsupported_language_falls_back_to_the_passport_default(): void
    {
        $passport = $this->publishedPassport(); // default_locale lv

        // German isn't built; ?lang and Accept-Language must both fall back to lv. The empty
        // Accept-Language overrides the test client's built-in 'en-us' default -- it stands
        // in for a scanner/browser that sends no language preference at all.
        $this->get("/p/{$passport->public_id}?lang=de", ['Accept-Language' => ''])
            ->assertOk()->assertSee('Ražotājs');
        $this->get("/p/{$passport->public_id}", ['Accept-Language' => 'de-DE,de;q=0.9'])
            ->assertOk()->assertSee('Ražotājs');
    }

    public function test_the_language_switcher_links_every_available_locale(): void
    {
        $passport = $this->publishedPassport();

        $this->get("/p/{$passport->public_id}?lang=en")
            ->assertSee('lang=lv', false)
            ->assertSee('Latviešu');
    }

    public function test_tiered_links_negotiate_language_too(): void
    {
        $passport = $this->publishedPassport();
        $token = $passport->accessTokens()->where('audience', 'repairer')->first()->token;

        $this->get("/p/{$passport->public_id}/repairer/{$token}?lang=lv")
            ->assertOk()
            ->assertSee('Kopšanas norādījumi')
            ->assertSee('Remontētājs');
    }

    public function test_json_ld_returns_the_negotiated_locale(): void
    {
        $passport = $this->publishedPassport();

        $response = $this->get("/p/{$passport->public_id}?format=json&lang=en")->assertOk();
        $this->assertSame('en', $response->json('locale'));
        $this->assertTrue(collect($response->json('fields'))->contains('label', 'Manufacturer'));

        // No language preference at all -> the passport's own default wins.
        $response = $this->get("/p/{$passport->public_id}?format=json", ['Accept-Language' => ''])->assertOk();
        $this->assertSame('lv', $response->json('locale'));
    }

    public function test_publishing_a_correction_rebuilds_every_locale(): void
    {
        $passport = $this->publishedPassport();
        $user = $passport->organization->members()->first();

        $this->actingAs($user)->post(route('passports.corrections.start', $passport));
        $this->put(route('passports.update', $passport), [
            'fields' => ['product_name' => 'Cotton Tee', 'manufacturer' => 'Corrected Mills'],
        ]);
        $this->post(route('passports.corrections.publish', $passport))->assertSessionHas('status');

        $this->get("/p/{$passport->public_id}?lang=lv")->assertSee('Corrected Mills');
        $this->get("/p/{$passport->public_id}?lang=en")->assertSee('Corrected Mills');
    }

    public function test_a_template_without_label_translations_falls_back_to_its_plain_label(): void
    {
        // Simulate a pre-i18n / org-custom template whose fields have no 'labels' map.
        $template = Template::where('key', 'generic')->first();
        $template->update([
            'field_schema' => collect($template->field_schema)
                ->map(fn ($f) => collect($f)->except('labels')->all())->all(),
        ]);

        $passport = $this->publishedPassport();

        $this->get("/p/{$passport->public_id}?lang=lv")->assertOk()->assertSee('Manufacturer');
    }

    private function publishedPassport(): Passport
    {
        $template = Template::where('key', 'generic')->first();
        $product = Product::create([
            'organization_id' => $this->org->id,
            'template_id' => $template->id,
            'name' => 'Cotton Tee',
            'category' => 'generic',
        ]);

        $passport = Passport::create([
            'organization_id' => $this->org->id,
            'product_id' => $product->id,
            'public_id' => (string) Str::uuid(),
            'identifier_scheme' => 'self',
            'status' => 'draft',
            'default_locale' => 'lv',
        ]);

        $passport->versions()->create([
            'version_no' => 1,
            'data' => [
                'product_name' => 'Cotton Tee',
                'manufacturer' => 'Acme Textiles',
                'care_instructions' => 'Wash cold',
            ],
            'content_hash' => 'pending',
            'locked' => false,
        ]);

        $user = User::firstOrCreate(
            ['email' => 'owner@example.com'],
            ['name' => 'Owner', 'email_verified_at' => now()],
        );
        if (! $this->org->members()->whereKey($user->id)->exists()) {
            $this->org->members()->attach($user->id, ['role' => 'owner']);
        }
        $user->forceFill(['current_organization_id' => $this->org->id])->save();

        return app(PassportPublisher::class)->publish($passport)->fresh();
    }
}

<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Passport;
use App\Models\Product;
use App\Models\Template;
use App\Models\User;
use Database\Seeders\TemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Manual per-locale translations of field VALUES: the manufacturer types them in the passport
 * form (base data stays the as-entered source record), untranslated fields fall back to the
 * original, and translations travel through publish + corrections into the locale snapshots.
 */
class PassportContentTranslationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TemplateSeeder::class);
        $this->org = Organization::create([
            'name' => 'Acme', 'slug' => 'acme-'.Str::lower(Str::random(6)),
            'plan' => 'free', 'status' => 'active', 'onboarding_completed_at' => now(),
        ]);
        $this->user = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'email_verified_at' => now()]);
        $this->org->members()->attach($this->user->id, ['role' => 'owner']);
        $this->user->forceFill(['current_organization_id' => $this->org->id])->save();
    }

    public function test_the_edit_form_offers_translation_fields_for_the_non_default_locales(): void
    {
        $passport = $this->draftPassport(); // default_locale lv -> translatable into en

        $this->actingAs($this->user)
            ->get(route('passports.edit', $passport))
            ->assertOk()
            ->assertSee('Translations &mdash; EN', false)
            ->assertSee('translations[en][care_instructions]', false)
            ->assertDontSee('translations[lv]', false); // never translate into the base language
    }

    public function test_saved_translations_are_served_on_the_translated_page_with_fallback(): void
    {
        $passport = $this->draftPassport();

        $this->actingAs($this->user)->put(route('passports.update', $passport), [
            'fields' => [
                'product_name' => 'Kokvilnas T-krekls',
                'manufacturer' => 'Acme Textiles',
                'care_instructions' => 'Mazgāt aukstā ūdenī',
            ],
            'translations' => [
                'en' => [
                    'product_name' => 'Cotton Tee',
                    'care_instructions' => 'Wash cold',
                    // manufacturer intentionally untranslated -> falls back
                ],
            ],
        ]);

        $this->post(route('passports.publish', $passport));
        $passport->refresh();
        $this->assertTrue($passport->isPublished());

        // LV page: the original values, including the title.
        $this->get("/p/{$passport->public_id}?lang=lv")
            ->assertSee('Kokvilnas T-krekls')
            ->assertSee('Mazgāt aukstā ūdenī');

        // EN page: translated values where given, original where not.
        $this->get("/p/{$passport->public_id}?lang=en")
            ->assertSee('Cotton Tee')
            ->assertSee('Wash cold')
            ->assertSee('Acme Textiles')
            ->assertDontSee('Mazgāt aukstā ūdenī');
    }

    public function test_a_translation_never_conjures_a_field_whose_base_value_is_empty(): void
    {
        $passport = $this->draftPassport();

        $this->actingAs($this->user)->put(route('passports.update', $passport), [
            'fields' => [
                'product_name' => 'Kokvilnas T-krekls',
                'manufacturer' => 'Acme Textiles',
                // description left empty in the base data
            ],
            'translations' => ['en' => ['description' => 'Ghost content']],
        ]);
        $this->post(route('passports.publish', $passport));

        $this->get("/p/{$passport->fresh()->public_id}?lang=en")->assertDontSee('Ghost content');
    }

    public function test_unknown_locales_and_fields_are_dropped_and_blanks_mean_fallback(): void
    {
        $passport = $this->draftPassport();

        $this->actingAs($this->user)->put(route('passports.update', $passport), [
            'fields' => ['product_name' => 'Krekls', 'manufacturer' => 'Acme'],
            'translations' => [
                'de' => ['product_name' => 'Hemd'],          // locale not configured
                'en' => ['nonexistent_key' => 'x', 'product_name' => '   '], // unknown field + blank
            ],
        ]);

        $this->assertNull($passport->versions()->orderByDesc('version_no')->first()->translations);
    }

    public function test_corrections_carry_and_can_change_translations(): void
    {
        $passport = $this->draftPassport();
        $this->actingAs($this->user)->put(route('passports.update', $passport), [
            'fields' => ['product_name' => 'Krekls', 'manufacturer' => 'Acme'],
            'translations' => ['en' => ['product_name' => 'Shirt']],
        ]);
        $this->post(route('passports.publish', $passport));
        $passport->refresh();

        // The correction draft starts from the live translations...
        $this->post(route('passports.corrections.start', $passport));
        $this->assertSame(
            ['en' => ['product_name' => 'Shirt']],
            $passport->refresh()->openCorrection()->translations,
        );

        // ...and can change them; publishing swaps the public EN page.
        $this->put(route('passports.update', $passport), [
            'fields' => ['product_name' => 'Krekls', 'manufacturer' => 'Acme'],
            'translations' => ['en' => ['product_name' => 'Corrected Shirt']],
        ]);
        $this->post(route('passports.corrections.publish', $passport))->assertSessionHas('status');

        $this->get("/p/{$passport->public_id}?lang=en")->assertSee('Corrected Shirt');
        $this->get("/p/{$passport->public_id}?lang=lv")->assertSee('Krekls');
    }

    private function draftPassport(): Passport
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
            'data' => [],
            'content_hash' => 'pending',
            'locked' => false,
        ]);

        return $passport;
    }
}

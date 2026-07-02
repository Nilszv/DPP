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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TieredPublicViewsTest extends TestCase
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

    public function test_publishing_issues_a_token_for_each_tiered_audience(): void
    {
        $passport = $this->publishedPassport();

        foreach (['repairer', 'recycler', 'authority'] as $audience) {
            $this->assertTrue($passport->accessTokens()->where('audience', $audience)->exists());
        }
    }

    public function test_consumer_and_full_audiences_never_get_an_access_token(): void
    {
        $passport = $this->publishedPassport();

        $this->assertFalse($passport->accessTokens()->where('audience', 'consumer')->exists());
        $this->assertFalse($passport->accessTokens()->where('audience', 'full')->exists());
    }

    public function test_snapshot_builder_produces_all_five_audiences_with_correct_field_filtering(): void
    {
        $passport = $this->publishedPassport();

        // 5 audiences x 2 public locales (lv default + en).
        $this->assertSame(10, PublishedSnapshot::where('passport_id', $passport->id)->count());
        $this->assertSame(5, PublishedSnapshot::where('passport_id', $passport->id)->where('locale', 'en')->count());

        // care_instructions: consumer + repairer only (per the seeded access_map).
        $repairer = PublishedSnapshot::where('passport_id', $passport->id)->where('audience', 'repairer')->where('locale', 'en')->first();
        $recycler = PublishedSnapshot::where('passport_id', $passport->id)->where('audience', 'recycler')->where('locale', 'en')->first();

        $this->assertTrue(collect($repairer->rendered['fields'])->contains('label', 'Care instructions'));
        $this->assertFalse(collect($recycler->rendered['fields'])->contains('label', 'Care instructions'));

        // recyclability: consumer + recycler + authority, NOT repairer.
        $this->assertTrue(collect($recycler->rendered['fields'])->contains('label', 'Recyclability / end-of-life'));
        $this->assertFalse(collect($repairer->rendered['fields'])->contains('label', 'Recyclability / end-of-life'));
    }

    public function test_repairer_link_resolves_the_repairer_audience_view(): void
    {
        $passport = $this->publishedPassport();
        $token = $passport->accessTokens()->where('audience', 'repairer')->first()->token;

        $this->get("/p/{$passport->public_id}/repairer/{$token}?lang=en")
            ->assertOk()
            ->assertSee('Care instructions')
            ->assertDontSee('Recyclability');
    }

    public function test_recycler_link_resolves_the_recycler_audience_view(): void
    {
        $passport = $this->publishedPassport();
        $token = $passport->accessTokens()->where('audience', 'recycler')->first()->token;

        $this->get("/p/{$passport->public_id}/recycler/{$token}?lang=en")
            ->assertOk()
            ->assertSee('Recyclability')
            ->assertDontSee('Care instructions');
    }

    public function test_authority_link_resolves_the_authority_audience_view(): void
    {
        $passport = $this->publishedPassport();
        $token = $passport->accessTokens()->where('audience', 'authority')->first()->token;

        $this->get("/p/{$passport->public_id}/authority/{$token}?lang=en")
            ->assertOk()
            ->assertSee('Country of manufacture');
    }

    public function test_tier_link_with_wrong_audience_for_a_valid_token_returns_404(): void
    {
        $passport = $this->publishedPassport();
        $repairerToken = $passport->accessTokens()->where('audience', 'repairer')->first()->token;

        $this->get("/p/{$passport->public_id}/recycler/{$repairerToken}")->assertNotFound();
    }

    public function test_tier_link_with_bogus_token_returns_404(): void
    {
        $passport = $this->publishedPassport();

        $this->get("/p/{$passport->public_id}/repairer/".Str::random(48))->assertNotFound();
    }

    public function test_tier_link_logs_a_scan(): void
    {
        $passport = $this->publishedPassport();
        $token = $passport->accessTokens()->where('audience', 'repairer')->first()->token;

        $this->get("/p/{$passport->public_id}/repairer/{$token}");

        $this->assertSame(1, DB::table('scan_events')->where('passport_id', $passport->id)->count());
    }

    public function test_regenerating_a_tier_token_invalidates_the_old_link(): void
    {
        $passport = $this->publishedPassport();
        $user = $this->editor($passport->organization);
        $oldToken = $passport->accessTokens()->where('audience', 'repairer')->first()->token;

        $this->actingAs($user)
            ->post(route('passports.tiers.regenerate', [$passport, 'repairer']))
            ->assertRedirect(route('passports.show', $passport));

        $this->get("/p/{$passport->public_id}/repairer/{$oldToken}")->assertNotFound();

        $newToken = $passport->accessTokens()->where('audience', 'repairer')->first()->token;
        $this->assertNotSame($oldToken, $newToken);
        $this->get("/p/{$passport->public_id}/repairer/{$newToken}")->assertOk();
    }

    private function editor(Organization $org): User
    {
        $user = User::create([
            'name' => 'Editor', 'email' => Str::lower(Str::random(6)).'@example.com', 'email_verified_at' => now(),
        ]);
        $org->members()->attach($user->id, ['role' => 'editor']);
        $user->forceFill(['current_organization_id' => $org->id])->save();

        return $user;
    }

    private function draftPassport(): Passport
    {
        $template = Template::where('key', 'generic')->first();
        $product = Product::create([
            'organization_id' => $this->org->id, 'template_id' => $template->id,
            'name' => 'Cotton Tee', 'category' => 'generic',
        ]);
        $passport = Passport::create([
            'organization_id' => $this->org->id, 'product_id' => $product->id,
            'public_id' => (string) Str::uuid(), 'identifier_scheme' => 'self',
            'status' => 'draft', 'default_locale' => 'lv',
        ]);
        $passport->versions()->create([
            'version_no' => 1,
            'data' => [
                'product_name' => 'Cotton Tee',
                'manufacturer' => 'Acme Textiles',
                'country_of_manufacture' => 'Latvia',
                'care_instructions' => 'Wash cold, line dry.',
                'recyclability' => 'Fully recyclable via textile collection.',
            ],
            'content_hash' => 'pending', 'locked' => false,
        ]);

        return $passport;
    }

    private function publishedPassport(): Passport
    {
        $passport = $this->draftPassport();
        app(PassportPublisher::class)->publish($passport);

        return $passport->refresh();
    }
}

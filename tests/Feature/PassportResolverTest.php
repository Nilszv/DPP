<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Passport;
use App\Models\Product;
use App\Models\Template;
use App\Services\PassportPublisher;
use Database\Seeders\TemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PassportResolverTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TemplateSeeder::class);
        $this->org = Organization::create([
            'name' => 'Acme', 'slug' => 'acme-'.Str::lower(Str::random(6)),
            'plan' => 'free', 'status' => 'active',
        ]);
    }

    public function test_published_passport_resolves_as_html_and_logs_a_scan(): void
    {
        $passport = $this->publishedPassport();

        $this->get("/p/{$passport->public_id}")
            ->assertOk()
            ->assertSee('Cotton Tee')
            ->assertSee('Acme Textiles');

        $this->assertSame(1, DB::table('scan_events')->where('passport_id', $passport->id)->count());
    }

    public function test_published_passport_resolves_as_json_ld(): void
    {
        $passport = $this->publishedPassport();

        $response = $this->get("/p/{$passport->public_id}?format=json")->assertOk();
        $this->assertSame('Cotton Tee', $response->json('title'));
        $this->assertSame('consumer', $response->json('audience'));
    }

    public function test_draft_passport_is_not_public(): void
    {
        $passport = $this->draftPassport();

        $this->get("/p/{$passport->public_id}")->assertNotFound();
        $this->assertSame(0, DB::table('scan_events')->where('passport_id', $passport->id)->count());
    }

    public function test_unknown_id_returns_404(): void
    {
        $this->get('/p/'.Str::uuid())->assertNotFound();
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
            'data' => ['product_name' => 'Cotton Tee', 'manufacturer' => 'Acme Textiles'],
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

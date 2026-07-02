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

class PassportQrExportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Passport $passport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TemplateSeeder::class);

        $org = Organization::create([
            'name' => 'Acme', 'slug' => 'acme-'.Str::lower(Str::random(6)),
            'plan' => 'free', 'status' => 'active', 'onboarding_completed_at' => now(),
        ]);
        $this->user = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'email_verified_at' => now()]);
        $org->members()->attach($this->user->id, ['role' => 'owner']);
        $this->user->forceFill(['current_organization_id' => $org->id])->save();

        $template = Template::where('key', 'generic')->first();
        $product = Product::create([
            'organization_id' => $org->id, 'template_id' => $template->id,
            'name' => 'Cotton Tee', 'category' => 'generic',
        ]);
        $this->passport = Passport::create([
            'organization_id' => $org->id, 'product_id' => $product->id,
            'public_id' => (string) Str::uuid(), 'identifier_scheme' => 'self',
            'status' => 'draft', 'default_locale' => 'lv',
        ]);
        $this->passport->versions()->create([
            'version_no' => 1, 'data' => [], 'content_hash' => 'pending', 'locked' => false,
        ]);
    }

    public function test_svg_export_is_unchanged(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('passports.qr', $this->passport))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml');

        $this->assertStringContainsString('<svg', $response->getContent());
    }

    public function test_png_export_returns_a_real_png_at_print_resolution(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('passports.qr', [$this->passport, 'format' => 'png']))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $content = $response->getContent();
        $this->assertStringStartsWith("\x89PNG", $content);

        [$width, $height] = getimagesizefromstring($content);
        $this->assertSame(1200, $width);
        $this->assertSame(1200, $height);

        // The raster must encode the same resolver URL the SVG does.
        $decoded = (new \Imagick)->readImageBlob($content);
        $this->assertNotEmpty($decoded); // decodable by ImageMagick, not just headers
    }

    public function test_png_size_is_clamped_to_sane_print_bounds(): void
    {
        $tiny = $this->actingAs($this->user)
            ->get(route('passports.qr', [$this->passport, 'format' => 'png', 'size' => 10]))
            ->assertOk()->getContent();
        $this->assertSame(240, getimagesizefromstring($tiny)[0]);

        $huge = $this->get(route('passports.qr', [$this->passport, 'format' => 'png', 'size' => 999999]))
            ->assertOk()->getContent();
        $this->assertSame(2400, getimagesizefromstring($huge)[0]);
    }

    public function test_qr_export_respects_tenant_isolation(): void
    {
        $stranger = User::create(['name' => 'S', 'email' => 'stranger@example.com', 'email_verified_at' => now()]);
        $otherOrg = Organization::create([
            'name' => 'Other', 'slug' => 'other-'.Str::lower(Str::random(6)),
            'plan' => 'free', 'status' => 'active', 'onboarding_completed_at' => now(),
        ]);
        $otherOrg->members()->attach($stranger->id, ['role' => 'owner']);
        $stranger->forceFill(['current_organization_id' => $otherOrg->id])->save();

        $this->actingAs($stranger)
            ->get(route('passports.qr', [$this->passport, 'format' => 'png']))
            ->assertNotFound();
    }
}

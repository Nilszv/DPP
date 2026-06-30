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

class PassportLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TemplateSeeder::class);

        $this->org = $this->makeOrg('free');
        $this->user = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'email_verified_at' => now()]);
        $this->org->members()->attach($this->user->id, ['role' => 'owner']);
        $this->user->forceFill(['current_organization_id' => $this->org->id])->save();
    }

    public function test_creating_a_passport_makes_a_draft_with_a_version(): void
    {
        $template = Template::where('key', 'generic')->first();

        $this->actingAs($this->user)
            ->post('/app/passports', ['product_name' => 'Cotton Tee', 'template_id' => $template->id])
            ->assertRedirect();

        $passport = Passport::first();
        $this->assertNotNull($passport);
        $this->assertSame('draft', $passport->status);
        $this->assertSame(1, $passport->versions()->count());
        $this->assertNotNull($passport->public_id);
    }

    public function test_cannot_publish_with_missing_required_fields(): void
    {
        $passport = $this->makeDraft($this->org, ['product_name' => 'Only name']); // manufacturer missing

        $this->actingAs($this->user)
            ->post("/app/passports/{$passport->id}/publish")
            ->assertSessionHas('error');

        $this->assertSame('draft', $passport->fresh()->status);
    }

    public function test_publishing_locks_data_and_builds_a_snapshot(): void
    {
        $passport = $this->makeDraft($this->org, ['product_name' => 'Cotton Tee', 'manufacturer' => 'Acme']);

        $this->actingAs($this->user)
            ->post("/app/passports/{$passport->id}/publish")
            ->assertRedirect(route('passports.show', $passport));

        $passport->refresh();
        $this->assertSame('published', $passport->status);
        $this->assertTrue($passport->currentVersion->locked);
        $this->assertSame(64, strlen($passport->currentVersion->content_hash));
        $this->assertNotNull($passport->retention_until);
        $this->assertTrue(
            $passport->snapshots()->where('audience', 'consumer')->exists()
        );
    }

    public function test_free_plan_quota_blocks_a_second_publish(): void
    {
        $first = $this->makeDraft($this->org, ['product_name' => 'One', 'manufacturer' => 'Acme']);
        $this->actingAs($this->user)->post("/app/passports/{$first->id}/publish");
        $this->assertSame('published', $first->fresh()->status);

        $second = $this->makeDraft($this->org, ['product_name' => 'Two', 'manufacturer' => 'Acme']);
        $this->actingAs($this->user)
            ->post("/app/passports/{$second->id}/publish")
            ->assertSessionHas('error');

        $this->assertSame('draft', $second->fresh()->status);
    }

    public function test_cannot_open_another_tenants_passport(): void
    {
        $otherOrg = $this->makeOrg('free');
        $foreign = $this->makeDraft($otherOrg, ['product_name' => 'Theirs', 'manufacturer' => 'Acme']);

        $this->actingAs($this->user)
            ->get("/app/passports/{$foreign->id}")
            ->assertNotFound();
    }

    private function makeOrg(string $plan): Organization
    {
        return Organization::create([
            'name' => 'Org '.Str::random(4),
            'slug' => 'org-'.Str::lower(Str::random(8)),
            'plan' => $plan,
            'status' => 'active',
        ]);
    }

    private function makeDraft(Organization $org, array $data): Passport
    {
        $template = Template::where('key', 'generic')->first();

        $product = Product::create([
            'organization_id' => $org->id,
            'template_id' => $template->id,
            'name' => $data['product_name'] ?? 'Product',
            'category' => 'generic',
        ]);

        $passport = Passport::create([
            'organization_id' => $org->id,
            'product_id' => $product->id,
            'public_id' => (string) Str::uuid(),
            'identifier_scheme' => 'self',
            'status' => 'draft',
            'default_locale' => 'lv',
        ]);

        $passport->versions()->create([
            'version_no' => 1,
            'data' => $data,
            'content_hash' => 'pending',
            'locked' => false,
        ]);

        return $passport;
    }
}

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

class PassportRolePolicyTest extends TestCase
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

    public function test_viewer_cannot_create_a_passport(): void
    {
        $viewer = $this->userWithRole('viewer');

        $this->actingAs($viewer)->get('/app/passports/create')->assertForbidden();
        $this->actingAs($viewer)
            ->post('/app/passports', ['product_name' => 'X', 'template_id' => $this->template()->id])
            ->assertForbidden();

        $this->assertSame(0, Passport::count());
    }

    public function test_viewer_cannot_publish(): void
    {
        $viewer = $this->userWithRole('viewer');
        $passport = $this->draft();

        $this->actingAs($viewer)
            ->post("/app/passports/{$passport->id}/publish")
            ->assertForbidden();

        $this->assertSame('draft', $passport->fresh()->status);
    }

    public function test_editor_can_create_and_publish(): void
    {
        $editor = $this->userWithRole('editor');
        $passport = $this->draft();

        $this->actingAs($editor)
            ->post("/app/passports/{$passport->id}/publish")
            ->assertRedirect(route('passports.show', $passport));

        $this->assertSame('published', $passport->fresh()->status);
    }

    private function template(): Template
    {
        return Template::where('key', 'generic')->first();
    }

    private function userWithRole(string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role),
            'email' => $role.'.'.Str::lower(Str::random(6)).'@example.com',
            'email_verified_at' => now(),
        ]);
        $this->org->members()->attach($user->id, ['role' => $role]);
        $user->forceFill(['current_organization_id' => $this->org->id])->save();

        return $user;
    }

    private function draft(): Passport
    {
        $product = Product::create([
            'organization_id' => $this->org->id, 'template_id' => $this->template()->id,
            'name' => 'Cotton Tee', 'category' => 'generic',
        ]);
        $passport = Passport::create([
            'organization_id' => $this->org->id, 'product_id' => $product->id,
            'public_id' => (string) Str::uuid(), 'identifier_scheme' => 'self',
            'status' => 'draft', 'default_locale' => 'lv',
        ]);
        $passport->versions()->create([
            'version_no' => 1,
            'data' => ['product_name' => 'Cotton Tee', 'manufacturer' => 'Acme'],
            'content_hash' => 'pending', 'locked' => false,
        ]);

        return $passport;
    }
}

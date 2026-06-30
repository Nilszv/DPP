<?php

namespace Tests\Feature;

use App\Mail\ContactSalesMail;
use App\Models\Organization;
use App\Models\Passport;
use App\Models\Product;
use App\Models\Template;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\TemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlanSeeder::class, TemplateSeeder::class]);
    }

    public function test_owner_can_switch_plan_in_manual_mode(): void
    {
        $org = $this->org('free');
        $owner = $this->userWithRole($org, 'owner');

        $this->actingAs($owner)
            ->post('/app/billing/switch', ['plan' => 'medium'])
            ->assertRedirect();

        $this->assertSame('medium', $org->fresh()->plan);
        $this->assertSame(5, $org->fresh()->publishedQuota());
    }

    public function test_viewer_cannot_switch_plan(): void
    {
        $org = $this->org('free');
        $viewer = $this->userWithRole($org, 'viewer');

        $this->actingAs($viewer)
            ->post('/app/billing/switch', ['plan' => 'medium'])
            ->assertForbidden();

        $this->assertSame('free', $org->fresh()->plan);
    }

    public function test_quota_follows_the_plan(): void
    {
        $this->assertSame(1, $this->org('free')->publishedQuota());
        $this->assertSame(5, $this->org('medium')->publishedQuota());
    }

    public function test_cannot_downgrade_when_published_passports_exceed_target_quota(): void
    {
        $org = $this->org('medium');                 // medium quota 5
        $owner = $this->userWithRole($org, 'owner');
        $this->makePublished($org);
        $this->makePublished($org);                  // 2 published; free allows 1

        $this->actingAs($owner)
            ->post('/app/billing/switch', ['plan' => 'free'])
            ->assertSessionHas('error');

        $this->assertSame('medium', $org->fresh()->plan);
    }

    public function test_can_downgrade_when_published_passports_fit(): void
    {
        $org = $this->org('medium');
        $owner = $this->userWithRole($org, 'owner');
        $this->makePublished($org);                  // 1 published; free allows 1

        $this->actingAs($owner)
            ->post('/app/billing/switch', ['plan' => 'free'])
            ->assertRedirect();

        $this->assertSame('free', $org->fresh()->plan);
    }

    public function test_contact_sales_emails_the_sales_inbox(): void
    {
        Mail::fake();
        $org = $this->org('medium');
        $owner = $this->userWithRole($org, 'owner');

        $this->actingAs($owner)
            ->post('/app/contact-sales', ['message' => 'We need a downgrade', 'interest' => 'Free'])
            ->assertRedirect();

        Mail::assertSent(ContactSalesMail::class, fn ($m) => $m->hasTo(config('dpp.sales_email')));
    }

    private function makePublished(Organization $org): void
    {
        $product = Product::create([
            'organization_id' => $org->id,
            'template_id' => Template::where('key', 'generic')->value('id'),
            'name' => 'P', 'category' => 'generic',
        ]);
        Passport::create([
            'organization_id' => $org->id, 'product_id' => $product->id,
            'public_id' => (string) Str::uuid(), 'identifier_scheme' => 'self',
            'status' => 'published', 'default_locale' => 'lv', 'published_at' => now(),
        ]);
    }

    private function org(string $plan): Organization
    {
        return Organization::create([
            'name' => 'Org '.Str::random(4),
            'slug' => 'org-'.Str::lower(Str::random(8)),
            'plan' => $plan,
            'status' => 'active',
        ]);
    }

    private function userWithRole(Organization $org, string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role),
            'email' => $role.'.'.Str::lower(Str::random(6)).'@example.com',
            'email_verified_at' => now(),
        ]);
        $org->members()->attach($user->id, ['role' => $role]);
        $user->forceFill(['current_organization_id' => $org->id])->save();

        return $user;
    }
}

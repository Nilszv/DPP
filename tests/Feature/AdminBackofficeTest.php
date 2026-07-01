<?php

namespace Tests\Feature;

use App\Exceptions\PublishException;
use App\Models\Organization;
use App\Models\Passport;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Template;
use App\Models\User;
use App\Services\PassportPublisher;
use Database\Seeders\PlanSeeder;
use Database\Seeders\TemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminBackofficeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_non_admin_cannot_access_the_back_office(): void
    {
        $user = User::create(['name' => 'Reg', 'email' => 'reg@example.com', 'email_verified_at' => now()]);

        $this->actingAs($user)->get(route('admin.overview'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.plans.index'))->assertForbidden();
    }

    public function test_admin_can_view_the_overview(): void
    {
        $this->actingAs($this->admin())->get(route('admin.overview'))->assertOk();
    }

    public function test_editing_a_plan_quota_changes_org_quota(): void
    {
        $org = $this->org('medium');
        $this->assertSame(5, $org->publishedQuota());

        $medium = Plan::where('key', 'medium')->first();
        $this->actingAs($this->admin())
            ->put(route('admin.plans.update', $medium), [
                'key' => 'medium', 'name' => 'Medium', 'price' => 9, 'interval' => 'month',
                'published_quota' => 10, 'is_public' => '1', 'active' => '1', 'sort' => 2,
            ])
            ->assertRedirect();

        $this->assertSame(10, $org->fresh()->publishedQuota());
    }

    public function test_admin_can_create_a_custom_plan_and_assign_it(): void
    {
        $org = $this->org('free');

        $this->actingAs($this->admin())
            ->post(route('admin.plans.store'), [
                'key' => 'custom-acme', 'name' => 'Acme Custom', 'price' => 49, 'interval' => 'month',
                'published_quota' => 25, 'is_public' => '0', 'active' => '1', 'sort' => 9,
            ])
            ->assertRedirect();

        $this->actingAs($this->admin())
            ->put(route('admin.organizations.update', $org), [
                'plan' => 'custom-acme', 'published_quota_override' => null, 'status' => 'active',
            ])
            ->assertRedirect();

        $org->refresh();
        $this->assertSame('custom-acme', $org->plan);
        $this->assertSame(25, $org->publishedQuota());
    }

    public function test_per_org_quota_override_takes_precedence(): void
    {
        $org = $this->org('free');

        $this->actingAs($this->admin())
            ->put(route('admin.organizations.update', $org), [
                'plan' => 'free', 'published_quota_override' => 50, 'status' => 'active',
            ])
            ->assertRedirect();

        $this->assertSame(50, $org->fresh()->publishedQuota());
    }

    public function test_is_admin_is_not_mass_assignable(): void
    {
        // Attempting to set is_admin via mass assignment must be ignored.
        $user = User::create([
            'name' => 'X', 'email' => 'x@example.com', 'email_verified_at' => now(), 'is_admin' => true,
        ]);

        $this->assertFalse($user->fresh()->isAdmin());
    }

    public function test_admin_can_set_a_custom_price_and_interval_for_an_org(): void
    {
        $org = $this->org('commercial');

        $this->actingAs($this->admin())
            ->put(route('admin.organizations.update', $org), [
                'plan' => 'commercial', 'published_quota_override' => null,
                'price_override' => 199, 'interval_override' => 'year', 'status' => 'active',
            ])
            ->assertRedirect();

        $org->refresh();
        $this->assertSame('199.00', $org->effectivePrice());
        $this->assertSame('year', $org->effectiveInterval());
    }

    public function test_suspended_org_is_blocked_from_the_app(): void
    {
        $org = $this->org('free');
        $org->update(['status' => 'suspended']);

        $user = User::create(['name' => 'M', 'email' => 'm@example.com', 'email_verified_at' => now()]);
        $org->members()->attach($user->id, ['role' => 'owner']);
        $user->forceFill(['current_organization_id' => $org->id])->save();

        $this->actingAs($user)->get('/app/passports')->assertForbidden();
    }

    public function test_suspended_org_cannot_publish(): void
    {
        $this->seed(TemplateSeeder::class);
        $org = $this->org('free');
        $org->update(['status' => 'suspended']);

        $template = Template::where('key', 'generic')->first();
        $product = Product::create([
            'organization_id' => $org->id, 'template_id' => $template->id,
            'name' => 'P', 'category' => 'generic',
        ]);
        $passport = Passport::create([
            'organization_id' => $org->id, 'product_id' => $product->id,
            'public_id' => (string) Str::uuid(), 'identifier_scheme' => 'self',
            'status' => 'draft', 'default_locale' => 'lv',
        ]);
        $passport->versions()->create([
            'version_no' => 1,
            'data' => ['product_name' => 'P', 'manufacturer' => 'Acme'],
            'content_hash' => 'pending', 'locked' => false,
        ]);

        $this->expectException(PublishException::class);
        app(PassportPublisher::class)->publish($passport);
    }

    public function test_organizations_can_be_searched_by_company_name(): void
    {
        $this->org('free')->update(['legal_name' => 'AlphaIndustries']);
        $this->org('free')->update(['legal_name' => 'BetaCorp']);

        $this->actingAs($this->admin())
            ->get(route('admin.organizations', ['q' => 'Alpha']))
            ->assertSee('AlphaIndustries')
            ->assertDontSee('BetaCorp');
    }

    public function test_organizations_can_be_searched_by_member_email(): void
    {
        $a = $this->org('free');
        $a->update(['legal_name' => 'AlphaCo']);
        $this->member($a, 'finder@acme.test');

        $b = $this->org('free');
        $b->update(['legal_name' => 'BetaCo']);

        $this->actingAs($this->admin())
            ->get(route('admin.organizations', ['q' => 'finder@acme.test']))
            ->assertSee('AlphaCo')
            ->assertDontSee('BetaCo');
    }

    public function test_organizations_can_be_filtered_by_status(): void
    {
        $this->org('free')->update(['legal_name' => 'ActiveCo', 'status' => 'active']);
        $this->org('free')->update(['legal_name' => 'SuspendedCo', 'status' => 'suspended']);

        $this->actingAs($this->admin())
            ->get(route('admin.organizations', ['status' => 'suspended']))
            ->assertSee('SuspendedCo')
            ->assertDontSee('ActiveCo');
    }

    public function test_organization_detail_view_shows_profile_and_members(): void
    {
        $org = $this->org('medium');
        $org->update(['legal_name' => 'DetailCo', 'country' => 'LV', 'city' => 'Riga']);
        $this->member($org, 'owner@detailco.test');

        $this->actingAs($this->admin())
            ->get(route('admin.organizations.show', $org))
            ->assertOk()
            ->assertSee('DetailCo')
            ->assertSee('owner@detailco.test')
            ->assertSee('Riga');
    }

    public function test_admin_can_delete_a_sole_member_user_and_their_organization(): void
    {
        $org = $this->org('free');
        $user = $this->member($org, 'solo@example.com');

        $this->actingAs($this->admin())
            ->delete(route('admin.users.delete', $user))
            ->assertRedirect(route('admin.organizations'));

        $this->assertNull(User::find($user->id));
        $this->assertNull(Organization::find($org->id));
    }

    public function test_admin_deleting_a_user_only_removes_their_membership_from_a_shared_org(): void
    {
        $org = $this->org('free');
        $owner = $this->member($org, 'owner@example.com');
        $editor = User::create(['name' => 'E', 'email' => 'editor@example.com', 'email_verified_at' => now()]);
        $org->members()->attach($editor->id, ['role' => 'editor']);

        $this->actingAs($this->admin())
            ->delete(route('admin.users.delete', $editor))
            ->assertRedirect(route('admin.organizations'));

        $this->assertNull(User::find($editor->id));
        $this->assertNotNull(Organization::find($org->id));
        $this->assertTrue($org->fresh()->members()->whereKey($owner->id)->exists());
    }

    public function test_admin_cannot_delete_a_sole_member_user_whose_org_has_published_passports(): void
    {
        $this->seed(TemplateSeeder::class);
        $org = $this->org('free');
        $user = $this->member($org, 'published@example.com');

        $template = Template::where('key', 'generic')->first();
        $product = Product::create([
            'organization_id' => $org->id, 'template_id' => $template->id,
            'name' => 'P', 'category' => 'generic',
        ]);
        Passport::create([
            'organization_id' => $org->id, 'product_id' => $product->id,
            'public_id' => (string) Str::uuid(), 'identifier_scheme' => 'self',
            'status' => 'published', 'default_locale' => 'lv',
        ]);

        $this->actingAs($this->admin())
            ->delete(route('admin.users.delete', $user))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNotNull(User::find($user->id));
        $this->assertNotNull(Organization::find($org->id));
    }

    public function test_admin_cannot_delete_the_sole_owner_of_an_org_with_other_members(): void
    {
        $org = $this->org('free');
        $owner = $this->member($org, 'owner2@example.com');
        $editor = User::create(['name' => 'E2', 'email' => 'editor2@example.com', 'email_verified_at' => now()]);
        $org->members()->attach($editor->id, ['role' => 'editor']);

        $this->actingAs($this->admin())
            ->delete(route('admin.users.delete', $owner))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNotNull(User::find($owner->id));
    }

    public function test_admin_cannot_delete_their_own_account(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->delete(route('admin.users.delete', $admin))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNotNull(User::find($admin->id));
    }

    private function member(Organization $org, string $email): User
    {
        $user = User::create(['name' => 'M', 'email' => $email, 'email_verified_at' => now()]);
        $org->members()->attach($user->id, ['role' => 'owner']);

        return $user;
    }

    private function admin(): User
    {
        $user = User::create([
            'name' => 'Admin', 'email' => 'admin.'.Str::lower(Str::random(5)).'@example.com',
            'email_verified_at' => now(),
        ]);
        $user->forceFill(['is_admin' => true])->save();

        return $user;
    }

    private function org(string $plan): Organization
    {
        return Organization::create([
            'name' => 'Org '.Str::random(4),
            'slug' => 'org-'.Str::lower(Str::random(8)),
            'plan' => $plan, 'status' => 'active',
        ]);
    }
}

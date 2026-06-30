<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\PlanSeeder;
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

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin', 'email' => 'admin.'.Str::lower(Str::random(5)).'@example.com',
            'email_verified_at' => now(), 'is_admin' => true,
        ]);
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

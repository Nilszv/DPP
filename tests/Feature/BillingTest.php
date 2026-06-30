<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);   // plans are DB-driven; selection validates against them
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

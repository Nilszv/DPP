<?php

namespace Tests\Feature;

use App\Mail\TeamInviteMail;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class TeamManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_manager_can_invite_a_teammate(): void
    {
        Mail::fake();
        $org = $this->org('medium');
        $owner = $this->member($org, 'owner', 'owner@acme.test');

        $this->actingAs($owner)
            ->post(route('team.invite'), ['email' => 'new@acme.test', 'role' => 'editor'])
            ->assertRedirect();

        $this->assertDatabaseHas('invitations', ['organization_id' => $org->id, 'email' => 'new@acme.test', 'role' => 'editor']);
        Mail::assertSent(TeamInviteMail::class);
    }

    public function test_invite_email_renders(): void
    {
        // Mail::fake skips rendering, so render the body explicitly to catch view bugs.
        $org = $this->org('medium');
        $invite = $this->invitation($org, 'render@acme.test', 'editor');

        $html = (new TeamInviteMail($invite, 'https://example.test/accept'))->render();

        $this->assertStringContainsString($org->name, $html);
        $this->assertStringContainsString('https://example.test/accept', $html);
    }

    public function test_seat_limit_blocks_inviting_beyond_the_plan(): void
    {
        $org = $this->org('free');                 // free = 1 seat
        $owner = $this->member($org, 'owner', 'owner@acme.test'); // that 1 seat is used

        $this->actingAs($owner)
            ->post(route('team.invite'), ['email' => 'new@acme.test', 'role' => 'viewer'])
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('invitations', ['email' => 'new@acme.test']);
    }

    public function test_viewer_cannot_invite(): void
    {
        $org = $this->org('medium');
        $viewer = $this->member($org, 'viewer', 'viewer@acme.test');

        $this->actingAs($viewer)
            ->post(route('team.invite'), ['email' => 'x@acme.test', 'role' => 'viewer'])
            ->assertForbidden();
    }

    public function test_invited_user_can_accept_and_joins_the_org(): void
    {
        $org = $this->org('medium');
        $this->member($org, 'owner', 'owner@acme.test');
        $invite = $this->invitation($org, 'joiner@acme.test', 'editor');

        $joiner = User::create(['name' => 'J', 'email' => 'joiner@acme.test', 'email_verified_at' => now()]);

        $this->actingAs($joiner)
            ->post(route('invitations.accept', $invite->token))
            ->assertRedirect(route('dashboard'));

        $this->assertTrue($org->members()->whereKey($joiner->id)->wherePivot('role', 'editor')->exists());
        $this->assertSame($org->id, $joiner->fresh()->current_organization_id);
        $this->assertNotNull($invite->fresh()->accepted_at);
    }

    public function test_accepting_with_a_different_email_is_forbidden(): void
    {
        $org = $this->org('medium');
        $invite = $this->invitation($org, 'invited@acme.test', 'viewer');
        $other = User::create(['name' => 'O', 'email' => 'other@acme.test', 'email_verified_at' => now()]);

        $this->actingAs($other)->post(route('invitations.accept', $invite->token))->assertForbidden();
        $this->assertFalse($org->members()->whereKey($other->id)->exists());
    }

    public function test_cannot_remove_the_last_owner(): void
    {
        $org = $this->org('medium');
        $owner = $this->member($org, 'owner', 'owner@acme.test');

        $this->actingAs($owner)
            ->delete(route('team.members.remove', $owner))
            ->assertSessionHas('error');

        $this->assertTrue($org->members()->whereKey($owner->id)->exists());
    }

    public function test_owner_can_remove_another_member(): void
    {
        $org = $this->org('medium');
        $owner = $this->member($org, 'owner', 'owner@acme.test');
        $editor = $this->member($org, 'editor', 'editor@acme.test');

        $this->actingAs($owner)
            ->delete(route('team.members.remove', $editor))
            ->assertRedirect();

        $this->assertFalse($org->members()->whereKey($editor->id)->exists());
    }

    public function test_user_can_switch_between_their_orgs(): void
    {
        $a = $this->org('medium');
        $b = $this->org('medium');
        $user = $this->member($a, 'owner', 'multi@acme.test');
        $b->members()->attach($user->id, ['role' => 'admin']);

        $this->actingAs($user)
            ->post(route('current-org.switch'), ['organization_id' => $b->id])
            ->assertRedirect(route('dashboard'));

        $this->assertSame($b->id, $user->fresh()->current_organization_id);
    }

    private function org(string $plan): Organization
    {
        return Organization::create([
            'name' => 'Org '.Str::random(4), 'slug' => 'org-'.Str::lower(Str::random(8)),
            'plan' => $plan, 'status' => 'active', 'onboarding_completed_at' => now(),
        ]);
    }

    private function member(Organization $org, string $role, string $email): User
    {
        $user = User::create(['name' => ucfirst($role), 'email' => $email, 'email_verified_at' => now()]);
        $org->members()->attach($user->id, ['role' => $role]);
        $user->forceFill(['current_organization_id' => $org->id])->save();

        return $user;
    }

    private function invitation(Organization $org, string $email, string $role): Invitation
    {
        return Invitation::create([
            'organization_id' => $org->id, 'email' => $email, 'role' => $role,
            'token' => Str::random(48), 'expires_at' => now()->addDays(7),
        ]);
    }
}

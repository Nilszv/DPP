<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AdminImpersonationTest extends TestCase
{
    use RefreshDatabase;

    public function test_happy_path_start_confirm_then_stop(): void
    {
        [$admin, $secret] = $this->makeConfirmedAdminSession();
        $org = $this->org();
        $member = $this->member($org, 'member@example.com');

        $this->from(route('admin.organizations.show', $org))
            ->post(route('admin.impersonate.start', $member))
            ->assertRedirect(route('admin.impersonate.confirm'));

        $this->assertSame($member->id, session('impersonate.target_id'));
        $this->get(route('admin.impersonate.confirm'))->assertOk();

        $code = app(Google2FA::class)->getCurrentOtp($secret);
        $this->post(route('admin.impersonate.confirm.submit'), ['code' => $code])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($member->fresh());
        $this->assertSame($admin->id, session('impersonate.original_admin_id'));

        $started = AuditLog::where('action', 'impersonation.started')->first();
        $this->assertNotNull($started);
        $this->assertSame($admin->id, $started->actor_id);
        $this->assertSame($member->id, $started->target);

        // Org context reflects the impersonated member's own org.
        $this->get(route('organization.show'))->assertOk()->assertSee($org->name);

        // Persistent banner shows on any /app page.
        $this->get('/app')->assertSee('You are impersonating')->assertSee($member->email);

        $this->post(route('impersonate.stop'))
            ->assertRedirect(route('admin.organizations.show', $org));

        $this->assertAuthenticatedAs($admin->fresh());
        $this->assertNull(session('impersonate.original_admin_id'));

        $ended = AuditLog::where('action', 'impersonation.ended')->first();
        $this->assertNotNull($ended);
        $this->assertSame($admin->id, $ended->actor_id);
        $this->assertSame($member->id, $ended->target);
    }

    public function test_wrong_step_up_code_does_not_start_impersonation(): void
    {
        [$admin] = $this->makeConfirmedAdminSession();
        $org = $this->org();
        $member = $this->member($org, 'member2@example.com');

        $this->post(route('admin.impersonate.start', $member));
        $this->post(route('admin.impersonate.confirm.submit'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertAuthenticatedAs($admin);
        $this->assertSame($member->id, session('impersonate.target_id'));
        $this->assertSame(0, AuditLog::where('action', 'impersonation.started')->count());
    }

    public function test_admin_cannot_impersonate_themselves(): void
    {
        [$admin] = $this->makeConfirmedAdminSession();

        $this->post(route('admin.impersonate.start', $admin))->assertSessionHas('error');

        $this->assertNull(session('impersonate.target_id'));
    }

    public function test_admin_cannot_impersonate_another_admin(): void
    {
        [$admin] = $this->makeConfirmedAdminSession();
        $otherAdmin = User::create(['name' => 'Other Admin', 'email' => 'other.admin@example.com', 'email_verified_at' => now()]);
        $otherAdmin->forceFill(['is_admin' => true])->save();

        $this->post(route('admin.impersonate.start', $otherAdmin))->assertSessionHas('error');

        $this->assertNull(session('impersonate.target_id'));
    }

    public function test_target_promoted_to_admin_between_start_and_confirm_is_rejected(): void
    {
        [$admin, $secret] = $this->makeConfirmedAdminSession();
        $org = $this->org();
        $member = $this->member($org, 'member3@example.com');

        $this->post(route('admin.impersonate.start', $member));

        // Promoted mid-flow: the confirm step must re-check fresh, not trust anything from start().
        $member->forceFill(['is_admin' => true])->save();

        $code = app(Google2FA::class)->getCurrentOtp($secret);
        $this->post(route('admin.impersonate.confirm.submit'), ['code' => $code])
            ->assertRedirect(route('admin.organizations'));

        $this->assertAuthenticatedAs($admin);
        $this->assertSame(0, AuditLog::where('action', 'impersonation.started')->count());
    }

    public function test_stop_when_not_impersonating_is_a_safe_noop(): void
    {
        [$admin] = $this->makeConfirmedAdminSession();

        $this->post(route('impersonate.stop'))->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($admin);
        $this->assertSame(0, AuditLog::count());

        $user = User::create(['name' => 'U', 'email' => 'plain.stop@example.com', 'email_verified_at' => now()]);
        $this->actingAs($user)->post(route('impersonate.stop'))->assertRedirect(route('dashboard'));
    }

    public function test_logout_mid_impersonation_fully_clears_state(): void
    {
        [$admin, $secret] = $this->makeConfirmedAdminSession();
        $org = $this->org();
        $member = $this->member($org, 'member4@example.com');

        $this->post(route('admin.impersonate.start', $member));
        $code = app(Google2FA::class)->getCurrentOtp($secret);
        $this->post(route('admin.impersonate.confirm.submit'), ['code' => $code]);
        $this->assertAuthenticatedAs($member->fresh());

        $this->post(route('logout'))->assertSessionMissing('impersonate.original_admin_id');

        $this->assertGuest();
    }

    /** @return array{0: User, 1: string} */
    private function makeConfirmedAdminSession(): array
    {
        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin.'.Str::lower(Str::random(6)).'@example.com',
            'email_verified_at' => now(),
        ]);
        $secret = app(TwoFactorService::class)->generateSecret();
        $admin->forceFill([
            'is_admin' => true,
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->actingAs($admin)->withSession(['2fa.passed' => true]);

        return [$admin, $secret];
    }

    private function org(): Organization
    {
        return Organization::create([
            'name' => 'Org '.Str::random(4),
            'slug' => 'org-'.Str::lower(Str::random(8)),
            'plan' => 'free', 'status' => 'active', 'onboarding_completed_at' => now(),
        ]);
    }

    private function member(Organization $org, string $email): User
    {
        $user = User::create(['name' => 'M', 'email' => $email, 'email_verified_at' => now()]);
        $org->members()->attach($user->id, ['role' => 'owner']);
        $user->forceFill(['current_organization_id' => $org->id])->save();

        return $user;
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LoginCodeService;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AdminTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_happy_path_confirms_and_logs_in(): void
    {
        $admin = $this->makeAdmin();

        $this->withSession(['2fa.pending_user_id' => $admin->id])
            ->get(route('2fa.setup'))
            ->assertOk();

        $secret = session('2fa.setup_secret');
        $this->assertNotNull($secret);

        $code = app(Google2FA::class)->getCurrentOtp($secret);

        $this->withSession(['2fa.pending_user_id' => $admin->id, '2fa.setup_secret' => $secret])
            ->post(route('2fa.setup.confirm'), ['code' => $code])
            ->assertRedirect(route('2fa.recovery-codes'));

        $this->assertAuthenticated();
        $this->assertNotNull($admin->fresh()->two_factor_confirmed_at);
        $this->assertCount(TwoFactorService::RECOVERY_CODE_COUNT, $admin->fresh()->two_factor_recovery_codes);
        $this->assertTrue(session('2fa.passed'));
    }

    public function test_setup_with_wrong_code_stays_guest_and_keeps_the_secret(): void
    {
        $admin = $this->makeAdmin();

        $this->withSession(['2fa.pending_user_id' => $admin->id])->get(route('2fa.setup'));
        $secret = session('2fa.setup_secret');

        $this->withSession(['2fa.pending_user_id' => $admin->id, '2fa.setup_secret' => $secret])
            ->post(route('2fa.setup.confirm'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
        $this->assertNull($admin->fresh()->two_factor_confirmed_at);
    }

    public function test_verify_flow_with_correct_code_authenticates(): void
    {
        [$admin, $secret] = $this->makeConfirmedAdmin();
        $code = app(Google2FA::class)->getCurrentOtp($secret);

        $this->withSession(['2fa.pending_user_id' => $admin->id])
            ->post(route('2fa.verify.confirm'), ['code' => $code])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $this->assertTrue(session('2fa.passed'));
    }

    public function test_verify_flow_with_incorrect_code_is_rejected(): void
    {
        [$admin] = $this->makeConfirmedAdmin();

        $this->withSession(['2fa.pending_user_id' => $admin->id])
            ->post(route('2fa.verify.confirm'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_clock_drift_window_accepts_adjacent_period_rejects_beyond(): void
    {
        [$admin, $secret] = $this->makeConfirmedAdmin();
        $google2fa = app(Google2FA::class);
        $counter = (int) floor(time() / 30);

        // One period back: within the ±1 window, must be accepted.
        $adjacent = $google2fa->oathTotp($secret, $counter - 1);
        $this->withSession(['2fa.pending_user_id' => $admin->id])
            ->post(route('2fa.verify.confirm'), ['code' => $adjacent])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();

        // Two periods back: outside the window, must be rejected.
        $this->post('/logout');
        $tooOld = $google2fa->oathTotp($secret, $counter - 2);
        $this->withSession(['2fa.pending_user_id' => $admin->id])
            ->post(route('2fa.verify.confirm'), ['code' => $tooOld])
            ->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_recovery_code_is_single_use(): void
    {
        [$admin, , $recoveryCodes] = $this->makeConfirmedAdmin(withRecoveryCodes: true);
        $recoveryCode = $recoveryCodes[0];

        $this->withSession(['2fa.pending_user_id' => $admin->id])
            ->post(route('2fa.verify.confirm'), ['recovery_code' => $recoveryCode])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
        $this->assertCount(TwoFactorService::RECOVERY_CODE_COUNT - 1, $admin->fresh()->two_factor_recovery_codes);

        $this->post('/logout');

        $this->withSession(['2fa.pending_user_id' => $admin->id])
            ->post(route('2fa.verify.confirm'), ['recovery_code' => $recoveryCode])
            ->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_stale_session_without_2fa_flag_is_redirected_to_reverify_then_allowed_through(): void
    {
        [$admin, $secret] = $this->makeConfirmedAdmin();

        // Simulate a remember-me-revived session: Auth::check() true, but this session never
        // set 2fa.passed (unlike actingAsAdmin(), which sets it deliberately for other tests).
        $this->actingAs($admin);

        $this->get(route('admin.overview'))->assertRedirect(route('2fa.reverify'));

        $code = app(Google2FA::class)->getCurrentOtp($secret);
        $this->post(route('2fa.reverify.confirm'), ['code' => $code])
            ->assertRedirect(route('admin.overview'));

        $this->assertTrue(session('2fa.passed'));
        $this->get(route('admin.overview'))->assertOk();
    }

    public function test_a_confirmed_admin_cannot_silently_replace_their_secret_via_setup(): void
    {
        [$admin, $originalSecret] = $this->makeConfirmedAdmin();

        // Stale/remembered session: authenticated, confirmed, but this session never set the
        // 2fa.passed flag -- exactly the state a hijacked/leaked session cookie would carry.
        $this->actingAs($admin);

        $this->get(route('2fa.setup'))->assertRedirect(route('2fa.reverify'));

        $newSecret = app(TwoFactorService::class)->generateSecret();
        $code = app(Google2FA::class)->getCurrentOtp($newSecret);
        $this->withSession(['2fa.setup_secret' => $newSecret])
            ->post(route('2fa.setup.confirm'), ['code' => $code])
            ->assertRedirect(route('2fa.reverify'));

        $this->assertSame($originalSecret, $admin->fresh()->two_factor_secret);
    }

    public function test_promotion_to_admin_mid_session_does_not_bypass_setup(): void
    {
        $user = User::create(['name' => 'U', 'email' => 'promoted@example.com', 'email_verified_at' => now()]);

        // A session flag alone must never be enough: simulate the worst case where
        // session('2fa.passed') is already true (however that happened) on a user who has
        // never configured 2FA -- the middleware must still force setup.
        $this->actingAs($user)->withSession(['2fa.passed' => true]);
        $this->get('/app')->assertOk();

        // Promoted to admin while this same session is still alive.
        $user->forceFill(['is_admin' => true])->save();

        $this->get(route('admin.overview'))->assertRedirect(route('2fa.setup'));
    }

    public function test_lockout_after_max_attempts_blocks_further_tries(): void
    {
        [$admin, $secret] = $this->makeConfirmedAdmin();

        for ($i = 0; $i < TwoFactorService::MAX_ATTEMPTS; $i++) {
            $this->withSession(['2fa.pending_user_id' => $admin->id])
                ->post(route('2fa.verify.confirm'), ['code' => '000000']);
        }

        $validCode = app(Google2FA::class)->getCurrentOtp($secret);
        $this->withSession(['2fa.pending_user_id' => $admin->id])
            ->post(route('2fa.verify.confirm'), ['code' => $validCode])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_admin_reset_2fa_console_command_clears_setup(): void
    {
        [$admin] = $this->makeConfirmedAdmin();

        $this->assertSame(0, Artisan::call('admin:reset-2fa', ['email' => $admin->email]));
        $admin->refresh();
        $this->assertNull($admin->two_factor_secret);
        $this->assertNull($admin->two_factor_recovery_codes);
        $this->assertNull($admin->two_factor_confirmed_at);
    }

    public function test_admin_reset_2fa_fails_for_non_admin(): void
    {
        $user = User::create(['name' => 'U', 'email' => 'plain@example.com', 'email_verified_at' => now()]);

        $this->assertSame(1, Artisan::call('admin:reset-2fa', ['email' => $user->email]));
    }

    public function test_admin_reset_2fa_fails_for_unknown_email(): void
    {
        $this->assertSame(1, Artisan::call('admin:reset-2fa', ['email' => 'nobody@example.com']));
    }

    public function test_non_admin_users_are_completely_unaffected(): void
    {
        $user = User::create(['name' => 'U', 'email' => 'plain2@example.com', 'email_verified_at' => now()]);

        // A stale session with no 2fa.passed flag is fine -- /app has no 2FA gate at all.
        $this->actingAs($user)->get('/app')->assertOk();
    }

    public function test_a_normal_non_admin_login_never_sets_the_2fa_passed_flag(): void
    {
        $user = User::create(['name' => 'U', 'email' => 'plain3@example.com', 'email_verified_at' => now()]);
        $code = app(LoginCodeService::class)->issue($user->email);

        $this->withSession(['login.email' => $user->email])
            ->post(route('login.verify'), ['code' => $code])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $this->assertNull(session('2fa.passed'));
    }

    private function makeAdmin(): User
    {
        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin.'.Str::lower(Str::random(6)).'@example.com',
            'email_verified_at' => now(),
        ]);
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    /** @return array{0: User, 1: string, 2: array<string>} */
    private function makeConfirmedAdmin(bool $withRecoveryCodes = false): array
    {
        $admin = $this->makeAdmin();
        $secret = app(TwoFactorService::class)->generateSecret();
        $codes = array_map(fn () => Str::upper(Str::random(4)).'-'.Str::upper(Str::random(4)), range(1, 10));

        $admin->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => array_map(fn ($c) => Hash::make($c), $codes),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return [$admin, $secret, $codes];
    }
}

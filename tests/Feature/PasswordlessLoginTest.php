<?php

namespace Tests\Feature;

use App\Mail\LoginCodeMail;
use App\Models\LoginCode;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoginCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordlessLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_requesting_a_code_emails_it_and_stores_only_a_hash(): void
    {
        Mail::fake();

        $this->post('/login', ['email' => 'Person@Example.com'])
            ->assertRedirect(route('login.code'));

        $record = LoginCode::where('email', 'person@example.com')->first();
        $this->assertNotNull($record);
        $this->assertNotEquals('', $record->code_hash);
        // The raw 6-digit code must never be stored in plaintext.
        $this->assertSame(60, strlen($record->code_hash)); // bcrypt hash length

        Mail::assertSent(LoginCodeMail::class);
    }

    public function test_first_login_creates_user_org_and_owner_membership(): void
    {
        Mail::fake();

        $this->post('/login', ['email' => 'newuser@example.com']);

        $code = $this->captureSentCode();

        $this->post('/login/code', ['code' => $code])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();

        $user = User::where('email', 'newuser@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->current_organization_id);

        $org = Organization::find($user->current_organization_id);
        $this->assertSame('free', $org->plan);
        $this->assertTrue(
            $user->organizations()->wherePivot('role', 'owner')->whereKey($org->id)->exists()
        );
    }

    public function test_wrong_code_does_not_authenticate(): void
    {
        $email = 'wrong@example.com';
        app(LoginCodeService::class)->issue($email);

        $this->withSession(['login.email' => $email])
            ->post('/login/code', ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_code_is_single_use(): void
    {
        $email = 'single@example.com';
        $code = app(LoginCodeService::class)->issue($email);

        $this->withSession(['login.email' => $email])->post('/login/code', ['code' => $code]);
        $this->assertAuthenticated();

        // Log out, try the same code again: must be rejected.
        $this->post('/logout');
        $this->withSession(['login.email' => $email])
            ->post('/login/code', ['code' => $code])
            ->assertSessionHasErrors('code');
    }

    public function test_expired_code_is_rejected(): void
    {
        $email = 'expired@example.com';
        $code = app(LoginCodeService::class)->issue($email);

        LoginCode::where('email', $email)->update(['expires_at' => Carbon::now()->subMinute()]);

        $this->withSession(['login.email' => $email])
            ->post('/login/code', ['code' => $code])
            ->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_code_burns_after_max_attempts(): void
    {
        $email = 'brute@example.com';
        $code = app(LoginCodeService::class)->issue($email);

        // Exhaust the attempt cap with wrong codes.
        for ($i = 0; $i < LoginCodeService::MAX_ATTEMPTS; $i++) {
            $this->withSession(['login.email' => $email])->post('/login/code', ['code' => '111111']);
        }

        // Even the correct code now fails (the code is burned).
        $this->withSession(['login.email' => $email])
            ->post('/login/code', ['code' => $code])
            ->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_resend_cooldown_blocks_immediate_second_request(): void
    {
        Mail::fake();

        $this->post('/login', ['email' => 'cooldown@example.com'])
            ->assertRedirect(route('login.code'));

        // Immediate second request must NOT send another email (this was the duplicate-email
        // bug). It simply routes back to the code page.
        $this->post('/login', ['email' => 'cooldown@example.com'])
            ->assertRedirect(route('login.code'));

        Mail::assertSent(LoginCodeMail::class, 1);
    }

    public function test_issuing_twice_leaves_only_one_active_code(): void
    {
        $service = app(LoginCodeService::class);
        $service->issue('multi@example.com');
        $service->issue('multi@example.com');

        $active = LoginCode::where('email', 'multi@example.com')
            ->whereNull('consumed_at')
            ->count();

        $this->assertSame(1, $active);
    }

    /** Pull the raw code out of the faked mail so we can complete the flow. */
    private function captureSentCode(): string
    {
        $captured = null;
        Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail) use (&$captured) {
            $captured = $mail->code;

            return true;
        });

        return $captured;
    }
}

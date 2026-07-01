<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\LoginCodeMail;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoginCodeService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PasswordlessController extends Controller
{
    /** Per-email guards (on top of the route-level per-IP throttle). */
    private const MAX_SENDS_PER_HOUR = 5;

    private const RESEND_COOLDOWN_SECONDS = 60;

    public function __construct(private LoginCodeService $codes) {}

    /** Step 1: email entry form. */
    public function showLogin(): View
    {
        return view('auth.login');
    }

    /** Step 2: issue + email a code, then show the code-entry form. */
    public function sendCode(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $email = strtolower(trim($data['email']));

        // Remember which email we are verifying (not whether it has an account).
        $request->session()->put('login.email', $email);

        // Allowlisted test addresses skip the throttle/cooldown entirely.
        $unthrottled = in_array($email, config('dpp.unthrottled_emails', []), true);

        if (! $unthrottled) {
            // Atomically reserve the resend cooldown BEFORE the (slow, synchronous) SMTP send.
            // Cache::add is a check-and-set: it returns false if a code was already sent within
            // the window. Reserving first means a second request that arrives while the first is
            // still sending is blocked here -- which is what was causing duplicate emails.
            $cooldownKey = 'login-cooldown:'.$email;
            if (! Cache::add($cooldownKey, true, self::RESEND_COOLDOWN_SECONDS)) {
                // A code was just sent (or is sending). Do not send another; go to the code page.
                return redirect()->route('login.code')
                    ->with('status', 'A code was already sent to '.$email.'. Please check your email.');
            }

            // Per-email hourly cap: stops an attacker email-bombing a victim from rotating IPs.
            $rateKey = 'login-send:'.$email;
            if (RateLimiter::tooManyAttempts($rateKey, self::MAX_SENDS_PER_HOUR)) {
                return back()->withErrors([
                    'email' => 'Too many code requests for this email. Please try again later.',
                ])->withInput();
            }
            RateLimiter::hit($rateKey, 3600);
        }

        $code = $this->codes->issue($email);
        Mail::to($email)->send(new LoginCodeMail($code, LoginCodeService::EXPIRY_MINUTES));

        return redirect()->route('login.code')
            ->with('status', 'We sent a 6-digit code to '.$email.'. It expires in '
                .LoginCodeService::EXPIRY_MINUTES.' minutes.');
    }

    /** Step 3: code-entry form (requires a pending email in session). */
    public function showVerify(Request $request): View|RedirectResponse
    {
        $email = $request->session()->get('login.email');
        if (! $email) {
            return redirect()->route('login');
        }

        return view('auth.verify', ['email' => $email]);
    }

    /** Step 4: verify the code; create the account if new, then log in. */
    public function verify(Request $request): RedirectResponse
    {
        $email = $request->session()->get('login.email');
        if (! $email) {
            return redirect()->route('login');
        }

        $request->validate(['code' => ['required', 'digits:6']]);

        if (! $this->codes->verify($email, $request->input('code'))) {
            return back()->withErrors(['code' => 'That code is invalid or has expired. Please try again.']);
        }

        $user = $this->findOrCreateUser($email);

        if ($user->isAdmin()) {
            // Admin accounts require TOTP two-factor -- do NOT Auth::login() yet. Regenerate
            // the session now (fixation-safe, right after the primary factor succeeds), stash
            // the pending user + remember choice, and branch to setup (first time) or verify.
            $request->session()->regenerate();
            $request->session()->forget('login.email');
            $request->session()->put('2fa.pending_user_id', $user->id);
            $request->session()->put('2fa.remember', $request->boolean('remember'));

            return $user->hasTwoFactorConfirmed()
                ? redirect()->route('2fa.verify')
                : redirect()->route('2fa.setup');
        }

        // Persistent "remember me" is opt-in, not forced.
        Auth::login($user, remember: $request->boolean('remember'));
        $request->session()->regenerate();
        $request->session()->forget('login.email');
        $request->session()->put('2fa.passed', true);

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /**
     * Find the user by email, or atomically create the user plus their first organization
     * (owner membership + current org). Wrapped in a transaction so a partial failure cannot
     * leave an orphaned user with no org. A concurrent first login that loses the unique-email
     * race re-fetches the winner's user instead of creating a duplicate.
     */
    private function findOrCreateUser(string $email): User
    {
        if ($user = User::where('email', $email)->first()) {
            return $user;
        }

        try {
            return DB::transaction(function () use ($email) {
                $name = Str::of($email)->before('@')->headline()->toString() ?: 'New user';

                $user = User::create([
                    'name' => $name,
                    'email' => $email,
                    'email_verified_at' => now(),   // verified by the code itself
                ]);

                $org = Organization::create([
                    'name' => $name."'s organization",
                    'slug' => Str::slug(Str::before($email, '@')).'-'.Str::lower(Str::random(6)),
                    'plan' => 'free',
                    'status' => 'active',
                ]);

                $org->members()->attach($user->id, ['role' => 'owner']);
                $user->update(['current_organization_id' => $org->id]);

                return $user;
            });
        } catch (QueryException $e) {
            // Lost the concurrent first-login race. On Postgres the unique-email violation
            // aborts and rolls back the WHOLE transaction, so the re-fetch must happen out
            // here, after rollback -- not inside the (now dead) transaction. The winner has
            // committed the user + org, so it is safe to read.
            if ($this->isUniqueViolation($e)) {
                return User::where('email', $email)->firstOrFail();
            }
            throw $e;
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // Postgres unique_violation SQLSTATE.
        return $e->getCode() === '23505';
    }
}

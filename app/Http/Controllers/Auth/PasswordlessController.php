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

        // Per-email hourly cap: stops an attacker email-bombing a victim from rotating IPs.
        $rateKey = 'login-send:'.$email;
        if (RateLimiter::tooManyAttempts($rateKey, self::MAX_SENDS_PER_HOUR)) {
            return back()->withErrors([
                'email' => 'Too many code requests for this email. Please try again later.',
            ])->withInput();
        }

        // Short resend cooldown: one code per minute per email.
        $cooldownKey = 'login-cooldown:'.$email;
        if (Cache::has($cooldownKey)) {
            return back()->withErrors([
                'email' => 'A code was just sent. Please wait a minute before requesting another.',
            ])->withInput();
        }

        $code = $this->codes->issue($email);
        Mail::to($email)->send(new LoginCodeMail($code, LoginCodeService::EXPIRY_MINUTES));

        RateLimiter::hit($rateKey, 3600);
        Cache::put($cooldownKey, true, self::RESEND_COOLDOWN_SECONDS);

        // Remember which email we are verifying (not whether it has an account).
        $request->session()->put('login.email', $email);

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

        // Persistent "remember me" is opt-in, not forced.
        Auth::login($user, remember: $request->boolean('remember'));
        $request->session()->regenerate();
        $request->session()->forget('login.email');

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

        return DB::transaction(function () use ($email) {
            $name = Str::of($email)->before('@')->headline()->toString() ?: 'New user';

            try {
                $user = User::create([
                    'name' => $name,
                    'email' => $email,
                    'email_verified_at' => now(),   // verified by the code itself
                ]);
            } catch (QueryException $e) {
                // Lost the race: another request already created this user (+ its org).
                if ($this->isUniqueViolation($e)) {
                    return User::where('email', $email)->firstOrFail();
                }
                throw $e;
            }

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
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // Postgres unique_violation SQLSTATE.
        return $e->getCode() === '23505';
    }
}

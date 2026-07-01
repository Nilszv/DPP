<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\QrService;
use App\Services\TwoFactorService;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * TOTP two-factor auth for admin users, layered on top of the passwordless login flow.
 *
 * Two distinct callers share the same setup/verify actions:
 *  - Pending login: PasswordlessController::verify() found an admin and stashed
 *    session('2fa.pending_user_id') without calling Auth::login() yet.
 *  - Stale/remembered session: EnsureAdminTwoFactorVerified found Auth::check() true but
 *    session('2fa.passed') missing (e.g. a remember-me cookie reviving a fresh session), and
 *    redirected here instead.
 * targetUser() resolves whichever applies; completeVerification() branches the same way to
 * decide whether to Auth::login() (pending) or just set the session flag (already logged in).
 */
class TwoFactorController extends Controller
{
    public function __construct(private TwoFactorService $twoFactor) {}

    /** First-time setup: show QR + manual secret + confirmation code input. */
    public function showSetup(Request $request): View|RedirectResponse
    {
        $user = $this->targetUser($request);
        if (! $user) {
            return redirect()->route('login');
        }

        // Already confirmed -- a stale/remembered session must not be able to silently replace
        // an existing secret. Re-setup only happens via an explicit, code-confirmed reset
        // (Admin\AdminTwoFactorController::reset()) or the admin:reset-2fa operator command.
        if ($user->hasTwoFactorConfirmed()) {
            return redirect()->route(Auth::check() ? '2fa.reverify' : '2fa.verify');
        }

        if (! $request->session()->has('2fa.setup_secret')) {
            $request->session()->put('2fa.setup_secret', $this->twoFactor->generateSecret());
        }
        $secret = $request->session()->get('2fa.setup_secret');

        return view('auth.2fa-setup', [
            'qrSvg' => app(QrService::class)->svg($this->twoFactor->provisioningUri($user, $secret), 240),
            'secret' => $secret,
        ]);
    }

    public function confirmSetup(Request $request): RedirectResponse
    {
        $user = $this->targetUser($request);
        if (! $user) {
            return redirect()->route('login');
        }

        if ($user->hasTwoFactorConfirmed()) {
            return redirect()->route(Auth::check() ? '2fa.reverify' : '2fa.verify');
        }

        $secret = $request->session()->get('2fa.setup_secret');
        if (! $secret) {
            return redirect()->route('2fa.setup');
        }

        $request->validate(['code' => ['required', 'digits:6']]);

        $codes = $this->twoFactor->confirm($user, $secret, $request->input('code'));
        if (! $codes) {
            return back()->withErrors(['code' => 'That code is invalid. Please try again.']);
        }

        $request->session()->forget('2fa.setup_secret');
        $request->session()->flash('2fa.recovery_codes_once', $codes);

        return $this->completeVerification($request, $user);
    }

    /** Code (or recovery code) entry -- shared by /login/2fa/verify and /2fa/reverify. */
    public function showVerify(Request $request): View|RedirectResponse
    {
        if (Auth::check() && ! $request->user()->isAdmin()) {
            return redirect()->route('dashboard');
        }

        $user = $this->targetUser($request);
        if (! $user) {
            return redirect()->route('login');
        }

        return view('auth.2fa-verify', [
            'action' => Auth::check() ? route('2fa.reverify.confirm') : route('2fa.verify.confirm'),
        ]);
    }

    public function verify(Request $request): RedirectResponse
    {
        if (Auth::check() && ! $request->user()->isAdmin()) {
            return redirect()->route('dashboard');
        }

        $user = $this->targetUser($request);
        if (! $user) {
            return redirect()->route('login');
        }

        return $this->attempt($request, $user, fn () => $this->completeVerification($request, $user));
    }

    private function attempt(Request $request, User $user, Closure $onSuccess): RedirectResponse
    {
        if ($this->twoFactor->tooManyAttempts($user)) {
            $seconds = $this->twoFactor->availableInSeconds($user);

            return back()->withErrors(['code' => "Too many failed attempts. Try again in {$seconds} seconds."]);
        }

        $request->validate([
            'code' => ['nullable', 'digits:6'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        $ok = $request->filled('recovery_code')
            ? $this->twoFactor->consumeRecoveryCode($user, $request->input('recovery_code'))
            : $this->twoFactor->verifyCode($user->two_factor_secret, (string) $request->input('code'));

        if (! $ok) {
            $this->twoFactor->recordFailedAttempt($user);

            return back()->withErrors(['code' => 'That code is invalid or has expired. Please try again.']);
        }

        $this->twoFactor->clearAttempts($user);

        return $onSuccess();
    }

    /** Fresh pending login completes Auth::login(); an already-authenticated stale session just gets the flag. */
    private function completeVerification(Request $request, User $user): RedirectResponse
    {
        if (Auth::check()) {
            $request->session()->put('2fa.passed', true);

            return redirect()->to($request->session()->pull('2fa.redirect_to', route('admin.overview')));
        }

        Auth::login($user, remember: (bool) $request->session()->pull('2fa.remember', false));
        $request->session()->regenerate();
        $request->session()->forget('2fa.pending_user_id');
        $request->session()->put('2fa.passed', true);

        return $request->session()->has('2fa.recovery_codes_once')
            ? redirect()->route('2fa.recovery-codes')
            : redirect()->intended(route('dashboard'));
    }

    /** The one-time recovery-codes display, read from the setup-confirm flash. */
    public function showRecoveryCodes(Request $request): View|RedirectResponse
    {
        if (! Auth::check()) {
            return redirect()->route('login');
        }

        return view('auth.2fa-recovery-codes', [
            'codes' => $request->session()->get('2fa.recovery_codes_once', []),
        ]);
    }

    private function targetUser(Request $request): ?User
    {
        if ($request->user()) {
            return $request->user();
        }

        $id = $request->session()->get('2fa.pending_user_id');

        return $id ? User::find($id) : null;
    }
}

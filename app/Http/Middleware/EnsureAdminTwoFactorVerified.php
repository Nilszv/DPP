<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after 'admin' on every /admin/* request. Requires BOTH a confirmed TOTP setup on the
 * user AND a session flag proving this particular session passed it -- checking the session
 * flag alone would let a user who gets promoted to admin mid-session (their existing session
 * already carries a flag from a completely unrelated earlier login) walk straight into /admin
 * with no 2FA configured at all. The session-flag half is what closes the separate remember-me
 * gap: a remember-me-revived session (Auth::check() true, but this session never went through
 * the 2FA step) is still forced to re-verify even though the user IS already confirmed.
 * Redirects, never aborts, so the admin's non-admin app session/work is not disturbed.
 */
class EnsureAdminTwoFactorVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user->hasTwoFactorConfirmed() && session('2fa.passed')) {
            return $next($request);
        }

        session(['2fa.redirect_to' => $request->fullUrl()]);

        return $user->hasTwoFactorConfirmed()
            ? redirect()->route('2fa.reverify')
            : redirect()->route('2fa.setup');
    }
}

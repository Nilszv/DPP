<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after 'admin' on every /admin/* request. Checks a SESSION flag, not a user/DB flag, so
 * a remember-me-revived session (Auth::check() true, but this particular session never went
 * through the 2FA step) is still forced to re-verify -- closes the gap where Laravel's own
 * remember-cookie auth would otherwise silently bypass 2FA entirely on a fresh session.
 * Redirects, never aborts, so the admin's non-admin app session/work is not disturbed.
 */
class EnsureAdminTwoFactorVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        if (session('2fa.passed')) {
            return $next($request);
        }

        session(['2fa.redirect_to' => $request->fullUrl()]);

        return $request->user()->hasTwoFactorConfirmed()
            ? redirect()->route('2fa.reverify')
            : redirect()->route('2fa.setup');
    }
}

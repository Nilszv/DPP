<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a suspended email account to the support page. A suspended user can still log in
 * (and reach /app/support + logout, which are NOT behind this middleware) but cannot use
 * the app or onboarding until an admin lifts the suspension. Runs after 'auth'.
 */
class EnsureUserNotSuspended
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isSuspended()) {
            return redirect()->route('support.show');
        }

        return $next($request);
    }
}

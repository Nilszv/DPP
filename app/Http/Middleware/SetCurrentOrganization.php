<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the authenticated user's current organization id into the container so the
 * OrganizationScope automatically isolates every tenant query to that org. Runs on
 * authenticated app routes only; the public resolver never sets it (it scopes explicitly).
 */
class SetCurrentOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user && $user->current_organization_id) {
            app()->instance('currentOrganizationId', $user->current_organization_id);
        }

        return $next($request);
    }
}

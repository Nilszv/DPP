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
            // Trust the stored org only if the user is STILL a member of it. A revoked
            // membership (or tampered value) must not grant tenant access.
            $isMember = $user->organizations()
                ->whereKey($user->current_organization_id)
                ->exists();

            if ($isMember) {
                app()->instance('currentOrganizationId', $user->current_organization_id);
            } else {
                // Fall back to any remaining membership, or clear it.
                $fallback = $user->organizations()->first();
                $user->forceFill(['current_organization_id' => $fallback?->id])->save();

                if ($fallback) {
                    app()->instance('currentOrganizationId', $fallback->id);
                }
            }
        }

        return $next($request);
    }
}

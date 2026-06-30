<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces a brand-new organization through the onboarding flow (company profile + legal
 * acceptance) before it can use the rest of the app. Runs after org.context/org.active;
 * the onboarding routes themselves are NOT behind this middleware.
 */
class EnsureOnboarded
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->bound('currentOrganizationId')) {
            $org = Organization::find(app('currentOrganizationId'));

            if ($org && ! $org->isOnboarded()) {
                return redirect()->route('onboarding.show');
            }
        }

        return $next($request);
    }
}

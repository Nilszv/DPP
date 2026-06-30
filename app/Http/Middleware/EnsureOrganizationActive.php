<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks tenant-app access when the current organization is suspended. Runs after
 * org.context (which binds the current org). The public passport resolver is NOT gated by
 * this -- a published passport must stay live even if the owning org is suspended.
 */
class EnsureOrganizationActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->bound('currentOrganizationId')) {
            $org = Organization::find(app('currentOrganizationId'));

            abort_if(
                $org && $org->isSuspended(),
                403,
                'This organization is suspended. Please contact support.'
            );
        }

        return $next($request);
    }
}

<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Applied to every tenant-owned model. Adds the tenant global scope and auto-fills
 * organization_id from the current org context on create, so application code never
 * has to remember to set it (and can't accidentally write to the wrong tenant).
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(function ($model): void {
            if (empty($model->organization_id) && app()->bound('currentOrganizationId')) {
                $model->organization_id = app('currentOrganizationId');
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Tenant-safe route-model binding. The global scope only constrains the query when the
     * org context is already bound, but route binding (SubstituteBindings) can run before the
     * org-context middleware. So we resolve the tenant explicitly here, using the SAME
     * membership-validated org as the middleware (so a revoked membership with a stale
     * current_organization_id cannot bind that org's records), and bind NOTHING (404) when no
     * valid org can be determined -- never an unconstrained lookup that could expose all tenants.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $orgId = app()->bound('currentOrganizationId')
            ? app('currentOrganizationId')
            : Auth::user()?->currentOrganizationIdIfMember();

        if ($orgId === null) {
            return null;
        }

        $field ??= $this->getRouteKeyName();

        return $this->newQuery()
            ->where($this->getTable().'.'.$field, $value)
            ->where($this->getTable().'.organization_id', $orgId)
            ->first();
    }
}

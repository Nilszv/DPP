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
     * org-context middleware. So we ALSO constrain explicitly here to the current org (from the
     * container, or the authenticated user's current org) -- a foreign id then resolves to null
     * (404) regardless of middleware ordering.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $field ??= $this->getRouteKeyName();
        $query = $this->newQuery()->where($this->getTable().'.'.$field, $value);

        $orgId = app()->bound('currentOrganizationId')
            ? app('currentOrganizationId')
            : Auth::user()?->current_organization_id;

        if ($orgId !== null) {
            $query->where($this->getTable().'.organization_id', $orgId);
        }

        return $query->first();
    }
}

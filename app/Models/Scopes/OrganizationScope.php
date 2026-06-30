<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Tenant isolation. When a "current organization" is bound in the container
 * (set by middleware after auth), every query on a tenant-owned model is automatically
 * constrained to that organization_id. No query can leak across tenants.
 *
 * When NO current org is bound (e.g. the public resolver looking up a passport by public_id,
 * console commands, queued jobs), the scope is inert -- those paths must scope explicitly.
 */
class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (app()->bound('currentOrganizationId')) {
            $builder->where(
                $model->getTable().'.organization_id',
                app('currentOrganizationId')
            );
        }
    }
}

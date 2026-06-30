<?php

namespace App\Policies;

use App\Models\Passport;
use App\Models\User;

/**
 * In-org role gating for passports (auto-discovered for the Passport model).
 * Roles: owner > admin > editor > viewer. Tenant isolation is handled separately by the
 * org scope / route binding; this only governs WHAT a member may do within their org.
 */
class PassportPolicy
{
    private function isEditor(User $user): bool
    {
        return in_array($user->roleInCurrentOrg(), ['owner', 'admin', 'editor'], true);
    }

    private function isManager(User $user): bool
    {
        return in_array($user->roleInCurrentOrg(), ['owner', 'admin'], true);
    }

    public function viewAny(User $user): bool
    {
        return $user->roleInCurrentOrg() !== null;
    }

    public function view(User $user, Passport $passport): bool
    {
        return $user->roleInCurrentOrg() !== null;
    }

    public function create(User $user): bool
    {
        return $this->isEditor($user);
    }

    public function update(User $user, Passport $passport): bool
    {
        return $this->isEditor($user);
    }

    public function publish(User $user, Passport $passport): bool
    {
        return $this->isEditor($user);
    }

    public function delete(User $user, Passport $passport): bool
    {
        return $this->isManager($user);
    }
}

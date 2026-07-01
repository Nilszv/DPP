<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'current_organization_id',
        // NOTE: is_admin is intentionally NOT mass-assignable. Set it via forceFill /
        // the `admin:grant` command only, so it can never be set from request input.
    ];

    /** Platform super-admin (back-office), distinct from in-org roles. */
    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    /** Whether this admin has completed TOTP setup (required before any admin access). */
    public function hasTwoFactorConfirmed(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    /**
     * Whether this email account is suspended (e.g. repeated duplicate-registration abuse).
     * A suspended user can still log in but is gated to the support page until an admin
     * lifts it. Distinct from Organization::isSuspended().
     */
    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /** Organizations this user belongs to, with their in-org role. */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /** Email is stored/compared lowercase (citext alternative). */
    public function setEmailAttribute(string $value): void
    {
        $this->attributes['email'] = strtolower(trim($value));
    }

    /** This user's role in the current organization (owner|admin|editor|viewer), or null. */
    public function roleInCurrentOrg(): ?string
    {
        $orgId = app()->bound('currentOrganizationId')
            ? app('currentOrganizationId')
            : $this->current_organization_id;

        if (! $orgId) {
            return null;
        }

        return $this->organizations()->whereKey($orgId)->first()?->pivot->role;
    }

    /** Owner/Admin may manage the org (billing, members). */
    public function canManageOrg(): bool
    {
        return in_array($this->roleInCurrentOrg(), ['owner', 'admin'], true);
    }

    /**
     * The org id this user may currently act in, VERIFIED against live membership.
     * Returns the stored current org if the user still belongs to it; otherwise the first
     * org they are a member of; otherwise null. Single source of truth for both the
     * org-context middleware and tenant route-model binding (so a revoked membership can
     * never leak access via a stale current_organization_id).
     */
    public function currentOrganizationIdIfMember(): ?string
    {
        $current = $this->current_organization_id;

        if ($current && $this->organizations()->whereKey($current)->exists()) {
            return $current;
        }

        return $this->organizations()->orderBy('organizations.created_at')->value('organizations.id');
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'suspended_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
}

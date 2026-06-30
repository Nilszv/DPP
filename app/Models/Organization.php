<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use HasUuids;

    protected $fillable = [
        'name', 'slug', 'plan', 'status', 'vat_id', 'custom_domain',
        'published_quota_override', 'price_override', 'interval_override',
        'legal_name', 'registration_number', 'address_line1', 'address_line2',
        'city', 'postal_code', 'country', 'contact_name', 'contact_email',
        'contact_phone', 'onboarding_completed_at',
    ];

    protected $casts = [
        'onboarding_completed_at' => 'datetime',
    ];

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function isOnboarded(): bool
    {
        return $this->onboarding_completed_at !== null;
    }

    /** Human-readable country name from the tax config. */
    public function countryName(): ?string
    {
        return $this->country ? config("tax.countries.{$this->country}.name") : null;
    }

    /** Standard VAT rate (%) for this org's country (applied later at billing time). */
    public function taxRate(): ?float
    {
        return $this->country ? config("tax.countries.{$this->country}.vat") : null;
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function passports(): HasMany
    {
        return $this->hasMany(Passport::class);
    }

    /** The DB-driven plan for this org, if present. */
    public function planModel(): ?Plan
    {
        return Plan::where('key', $this->plan)->first();
    }

    /**
     * Published-DPP quota (server-side enforced; UI is never the gate). Precedence:
     * per-org override -> DB plan -> config fallback. null quota = unlimited.
     */
    public function publishedQuota(): int
    {
        if ($this->published_quota_override !== null) {
            return (int) $this->published_quota_override;
        }

        if ($plan = $this->planModel()) {
            return $plan->effectiveQuota();
        }

        return (int) (config("billing.plans.{$this->plan}.published_quota")
            ?? config('billing.plans.free.published_quota'));
    }

    public function planName(): string
    {
        return $this->planModel()?->name
            ?? config("billing.plans.{$this->plan}.name", ucfirst($this->plan));
    }

    /** Effective price: per-org override -> plan price. null = custom/contact (unset). */
    public function effectivePrice(): ?string
    {
        if ($this->price_override !== null) {
            return $this->price_override;
        }

        return $this->planModel()?->price;
    }

    /** Effective billing interval: per-org override -> plan interval. */
    public function effectiveInterval(): ?string
    {
        return $this->interval_override ?? $this->planModel()?->interval;
    }

    public function publishedCount(): int
    {
        return $this->passports()->where('status', 'published')->count();
    }

    /**
     * Whether this org may switch to the given plan. A downgrade is blocked when the org
     * already has more published passports than the target plan allows: published passports
     * are a 10+ year hosting duty, so someone must keep paying for them. Such a move must go
     * through sales (contact form), never self-service.
     */
    public function fitsPlan(Plan $plan): bool
    {
        return $this->publishedCount() <= $plan->effectiveQuota();
    }
}

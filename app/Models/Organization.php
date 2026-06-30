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
    ];

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
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
}

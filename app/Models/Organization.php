<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'slug', 'plan', 'status', 'vat_id', 'custom_domain'];

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

    /** Published-DPP quota for this org's plan (server-side enforced; UI is never the gate). */
    public function publishedQuota(): int
    {
        return (int) (config("billing.plans.{$this->plan}.published_quota")
            ?? config('billing.plans.free.published_quota'));
    }

    public function planName(): string
    {
        return config("billing.plans.{$this->plan}.name", ucfirst($this->plan));
    }

    public function publishedCount(): int
    {
        return $this->passports()->where('status', 'published')->count();
    }
}

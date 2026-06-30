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

    /** Published-DPP quota per plan (server-side enforced; UI is never the gate). */
    public const PUBLISHED_QUOTA = [
        'free' => 1,
        'medium' => 5,
        'commercial' => PHP_INT_MAX,   // custom / high volume
    ];

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

    public function publishedQuota(): int
    {
        return self::PUBLISHED_QUOTA[$this->plan] ?? self::PUBLISHED_QUOTA['free'];
    }
}

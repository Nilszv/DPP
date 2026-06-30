<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * DB-driven plan catalogue (editable from the admin back-office). Quota of null means
 * unlimited; price of null means custom/contact. The config in config/billing.php is only
 * a seed/fallback now -- the plans table is the source of truth.
 */
class Plan extends Model
{
    use HasUuids;

    protected $fillable = [
        'key', 'name', 'price', 'interval', 'published_quota',
        'is_public', 'active', 'stripe_price_id', 'sort',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'published_quota' => 'integer',
        'is_public' => 'boolean',
        'active' => 'boolean',
        'sort' => 'integer',
    ];

    /** Effective numeric quota (null in DB = unlimited). */
    public function effectiveQuota(): int
    {
        return $this->published_quota ?? PHP_INT_MAX;
    }
}

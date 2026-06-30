<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * System-of-delivery row. The resolver reads exactly one of these per scan.
 * Composite primary key (passport_id, audience, locale); no surrogate id, no incrementing.
 */
class PublishedSnapshot extends Model
{
    public const UPDATED_AT = 'updated_at';
    public const CREATED_AT = null;

    public $incrementing = false;
    protected $primaryKey = null;   // composite key; we always upsert/lookup by all three

    protected $fillable = ['passport_id', 'audience', 'locale', 'rendered', 'etag'];

    protected $casts = [
        'rendered' => 'array',
    ];

    public function passport(): BelongsTo
    {
        return $this->belongsTo(Passport::class);
    }
}

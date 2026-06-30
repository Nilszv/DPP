<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only. Once locked (on publish) the body is never updated -- corrections create a
 * new version. content_hash is sha256 of canonical JSON of `data`.
 */
class PassportVersion extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;   // append-only: created_at only

    protected $fillable = [
        'passport_id', 'version_no', 'data', 'content_hash', 'created_by', 'locked',
    ];

    protected $casts = [
        'data' => 'array',
        'locked' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function passport(): BelongsTo
    {
        return $this->belongsTo(Passport::class);
    }
}

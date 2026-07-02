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
        'passport_id', 'version_no', 'data', 'translations', 'content_hash', 'created_by', 'locked',
    ];

    protected $casts = [
        'data' => 'array',
        'translations' => 'array',
        'locked' => 'boolean',
        'created_at' => 'datetime',
    ];

    /**
     * The value to serve for a field in a locale: the manufacturer's own translation when one
     * exists, otherwise the as-entered base value. content_hash intentionally keeps covering
     * only `data` -- the source-language record is the legally binding master; translations
     * are supplementary renderings of it (each snapshot still carries its own etag).
     */
    public function valueFor(string $key, string $locale): ?string
    {
        $translated = $this->translations[$locale][$key] ?? null;

        return ($translated !== null && trim((string) $translated) !== '')
            ? $translated
            : ($this->data[$key] ?? null);
    }

    public function passport(): BelongsTo
    {
        return $this->belongsTo(Passport::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

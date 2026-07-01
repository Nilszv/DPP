<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * System-of-delivery row. The resolver reads exactly one of these per scan.
 * Composite primary key (passport_id, audience, locale); no surrogate id, no incrementing.
 *
 * Eloquent has no native composite-key support: with $primaryKey left null, the base
 * setKeysForSaveQuery()/setKeysForSelectQuery() build a `where(null, '=', null)` constraint,
 * which silently updates/matches EVERY row in the table on save()/fresh()/refresh(). Both are
 * overridden below to constrain by the actual composite key instead.
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

    protected function setKeysForSaveQuery($query)
    {
        return $this->addCompositeKeyConstraints($query);
    }

    protected function setKeysForSelectQuery($query)
    {
        return $this->addCompositeKeyConstraints($query);
    }

    private function addCompositeKeyConstraints($query)
    {
        return $query
            ->where('passport_id', $this->getAttribute('passport_id'))
            ->where('audience', $this->getAttribute('audience'))
            ->where('locale', $this->getAttribute('locale'));
    }
}

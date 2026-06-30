<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Immutable record of an org/user accepting a specific legal document version. */
class LegalAcceptance extends Model
{
    use HasUuids;

    public $timestamps = false;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected $fillable = [
        'organization_id', 'user_id', 'document_key', 'document_version', 'ip_hash', 'accepted_at',
    ];

    protected $casts = [
        'document_version' => 'integer',
        'accepted_at' => 'datetime',
    ];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A pending team invitation. Consumed when the invited email logs in and accepts. */
class Invitation extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id', 'email', 'role', 'token', 'invited_by', 'expires_at', 'accepted_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}

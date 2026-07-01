<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A durable, revocable secret granting access to one tiered audience view of a passport. */
class PassportAccessToken extends Model
{
    use HasUuids;

    protected $fillable = ['passport_id', 'audience', 'token'];

    public function passport(): BelongsTo
    {
        return $this->belongsTo(Passport::class);
    }

    /** Publish-time (or backfill) issuance. Rotates the token if a row already exists. */
    public static function issue(Passport $passport, string $audience): self
    {
        return self::updateOrCreate(
            ['passport_id' => $passport->id, 'audience' => $audience],
            ['token' => self::newToken()],
        );
    }

    /** Invalidates the current link immediately -- the old token no longer matches any row. */
    public function regenerate(): void
    {
        $this->update(['token' => self::newToken()]);
    }

    private static function newToken(): string
    {
        return Str::random(48);
    }
}

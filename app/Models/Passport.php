<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Passport extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $fillable = [
        'organization_id', 'product_id', 'public_id', 'identifier_scheme',
        'gtin', 'serial', 'batch', 'status', 'current_version_id',
        'default_locale', 'published_at', 'retention_until',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'retention_until' => 'date',
    ];

    /** Columns Eloquent should treat as UUIDs when auto-generating (only the PK). */
    public function uniqueIds(): array
    {
        return ['id'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PassportVersion::class);
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(PassportVersion::class, 'current_version_id');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(PublishedSnapshot::class);
    }

    public function accessTokens(): HasMany
    {
        return $this->hasMany(PassportAccessToken::class);
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    /**
     * The open correction draft on a published passport, if any: a version newer than the
     * one being served publicly, not yet locked. Draft passports never have one -- their
     * single working version is "the draft", not a correction to anything.
     */
    public function openCorrection(): ?PassportVersion
    {
        if (! $this->isPublished()) {
            return null;
        }

        $latest = $this->versions()->orderByDesc('version_no')->first();

        return $latest && ! $latest->locked ? $latest : null;
    }

    /**
     * The permanent public URL encoded into the QR carrier. GS1 Digital Link form when the
     * passport has a GTIN; opaque /p/{public_id} fallback otherwise. linkType is NEVER baked
     * into the carrier -- the resolver negotiates audience/format server-side.
     */
    public function resolverUrl(): string
    {
        $base = rtrim(config('dpp.passport_base_url'), '/');

        if ($this->identifier_scheme === 'gs1' && $this->gtin) {
            $url = "{$base}/01/{$this->gtin}";
            if ($this->serial) {
                $url .= "/21/{$this->serial}";
            }

            return $url;
        }

        return "{$base}/p/{$this->public_id}";
    }

    /** Sibling to resolverUrl() -- a tiered (repairer/recycler/authority) access link. */
    public function tierUrl(string $audience, string $token): string
    {
        $base = rtrim(config('dpp.passport_base_url'), '/');

        return "{$base}/p/{$this->public_id}/{$audience}/{$token}";
    }
}

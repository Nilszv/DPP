<?php

namespace App\Services;

use App\Exceptions\PublishException;
use App\Models\AuditLog;
use App\Models\Passport;
use App\Models\PassportAccessToken;
use App\Support\CanonicalJson;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Publishing = placing a passport on the market. This is the regulated transition:
 * mandatory fields must be complete, the org's published quota is enforced server-side,
 * the master data is locked (append-only + content hash), and the read-side snapshot is built.
 */
class PassportPublisher
{
    /** Minimum retention: published_at + 10 years (product lifetime + ~10y; lifetime unknown here). */
    private const RETENTION_YEARS = 10;

    public function __construct(private SnapshotBuilder $snapshots) {}

    /**
     * @throws PublishException
     */
    public function publish(Passport $passport): Passport
    {
        if ($passport->isPublished()) {
            return $passport;
        }

        if ($passport->organization->isSuspended()) {
            throw new PublishException('This organization is suspended and cannot publish passports.');
        }

        $template = $passport->product->template;
        $version = $passport->versions()->orderByDesc('version_no')->first();

        if (! $version) {
            throw new PublishException('This passport has no data to publish yet.');
        }

        // Required-field check is about this passport's own data (no concurrency concern).
        $this->assertRequiredFieldsComplete($template->requiredFieldKeys(), $version->data ?? []);

        return DB::transaction(function () use ($passport, $version) {
            // Serialize publishes within an organization so two concurrent publishes cannot
            // both pass the quota check. Two-arg advisory-lock keyspace (distinct from the
            // single-arg login lock); released automatically at transaction end.
            DB::statement('SELECT pg_advisory_xact_lock(?, hashtext(?))', [1, $passport->organization_id]);

            // Re-read state INSIDE the lock so a status/quota change that landed mid-flight wins.
            $passport->refresh();
            if ($passport->isPublished()) {
                return $passport; // another concurrent request already published it
            }
            if ($passport->organization()->value('status') === 'suspended') {
                throw new PublishException('This organization is suspended and cannot publish passports.');
            }
            $this->assertWithinQuota($passport);

            // Lock the master data: hash it and freeze it (append-only from here).
            $version->update([
                'content_hash' => CanonicalJson::hash($version->data ?? []),
                'locked' => true,
            ]);

            $publishedAt = Carbon::now();
            $passport->update([
                'status' => 'published',
                'current_version_id' => $version->id,
                'published_at' => $publishedAt,
                'retention_until' => $publishedAt->copy()->addYears(self::RETENTION_YEARS)->toDateString(),
            ]);

            $passport->refresh();
            $this->snapshots->build($passport, $version, $passport->product->template);

            foreach (['repairer', 'recycler', 'authority'] as $audience) {
                PassportAccessToken::issue($passport, $audience);
            }

            return $passport;
        });
    }

    /**
     * Publish an open correction on an ALREADY-published passport: the same regulated gate as
     * publish() (required fields, suspended-org block, locked master data) minus the quota
     * check -- the passport is already on the market, so the published count is unchanged, and
     * an org that slipped over quota (e.g. an admin plan change) must still be able to correct
     * a live passport. Nothing public-facing rotates: public_id, access tokens, published_at,
     * and retention_until all stay -- only which version the snapshots serve changes.
     *
     * @throws PublishException
     */
    public function publishCorrection(Passport $passport): Passport
    {
        if (! $passport->isPublished()) {
            throw new PublishException('Only a published passport can take a correction.');
        }

        if ($passport->organization->isSuspended()) {
            throw new PublishException('This organization is suspended and cannot publish corrections.');
        }

        $template = $passport->product->template;

        return DB::transaction(function () use ($passport, $template) {
            // Same per-org lock as publish(): serializes against concurrent publishes,
            // double-submitted corrections, and startCorrection() racing this swap.
            DB::statement('SELECT pg_advisory_xact_lock(?, hashtext(?))', [1, $passport->organization_id]);

            $passport->refresh();
            $version = $passport->openCorrection();
            if (! $version) {
                throw new PublishException('There is no open correction to publish.');
            }
            if ($passport->organization()->value('status') === 'suspended') {
                throw new PublishException('This organization is suspended and cannot publish corrections.');
            }

            $this->assertRequiredFieldsComplete($template->requiredFieldKeys(), $version->data ?? []);

            $previous = $passport->currentVersion;

            $version->update([
                'content_hash' => CanonicalJson::hash($version->data ?? []),
                'locked' => true,
            ]);

            $passport->update(['current_version_id' => $version->id]);

            $passport->refresh();
            $this->snapshots->build($passport, $version, $template);

            // Inside the transaction: a correction that swaps the public record must never
            // happen without its audit row, and vice versa. Translations get their own hash
            // pair: content_hash covers only the base data, so a translation-only correction
            // would otherwise be indistinguishable from a no-op in this row.
            AuditLog::record(
                action: 'passport.correction.published',
                target: $passport->id,
                meta: [
                    'from_version_no' => $previous?->version_no,
                    'from_content_hash' => $previous?->content_hash,
                    'from_translations_hash' => CanonicalJson::hash($previous?->translations ?? []),
                    'to_version_no' => $version->version_no,
                    'to_content_hash' => $version->content_hash,
                    'to_translations_hash' => CanonicalJson::hash($version->translations ?? []),
                ],
                organizationId: $passport->organization_id,
            );

            return $passport;
        });
    }

    private function assertRequiredFieldsComplete(array $requiredKeys, array $data): void
    {
        $missing = [];
        foreach ($requiredKeys as $key) {
            if (! isset($data[$key]) || trim((string) $data[$key]) === '') {
                $missing[] = $key;
            }
        }

        if ($missing) {
            throw new PublishException('Complete all required fields before publishing: '.implode(', ', $missing).'.');
        }
    }

    private function assertWithinQuota(Passport $passport): void
    {
        $org = $passport->organization;
        $publishedCount = $org->passports()->where('status', 'published')->count();

        if ($publishedCount >= $org->publishedQuota()) {
            throw new PublishException(
                'Your plan ('.$org->plan.') allows '.$org->publishedQuota().' published passport(s). '
                .'Upgrade to publish more.'
            );
        }
    }
}

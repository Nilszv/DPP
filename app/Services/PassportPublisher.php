<?php

namespace App\Services;

use App\Exceptions\PublishException;
use App\Models\Passport;
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

            // Re-read state and enforce quota INSIDE the lock, where the count is accurate.
            $passport->refresh();
            if ($passport->isPublished()) {
                return $passport; // another concurrent request already published it
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

            $this->snapshots->build($passport->refresh(), $version, $passport->product->template);

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

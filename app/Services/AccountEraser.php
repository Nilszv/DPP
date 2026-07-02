<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * GDPR Art. 17 erasure of a user account, shared by the self-service privacy page and the
 * admin delete-user tool. Beyond deleting the user row (memberships cascade), it removes or
 * anonymizes every place personal data hides: login codes and invitations keyed by EMAIL (no
 * FK reaches them), invited_by back-references, database sessions, and email addresses inside
 * audit_log meta. The audit EVENTS are kept (regulatory trail, Art. 17(3)(b) legal-obligation
 * carve-out) -- only the personal identifiers inside them are redacted; the dangling actor
 * uuid renders as "system / deleted user" in the admin browser. Legal acceptances have no
 * user FK, so acceptance evidence survives for its 10-year duty wherever the org survives.
 */
class AccountEraser
{
    /** Why this account cannot be erased right now, or null if it can. */
    public function blocker(User $user): ?string
    {
        foreach ($this->orgsWithCounts($user) as $org) {
            $isSoleOwner = $org->pivot->role === 'owner'
                && $org->members()->wherePivot('role', 'owner')->count() === 1;

            if ($org->members_count > 1 && $isSoleOwner) {
                return "Sole owner of \"{$org->name}\", which has other members. Reassign ownership first.";
            }

            if ($org->members_count === 1 && $org->publishedCount() > 0) {
                // Published DPPs are a permanent public record (10-year retention duty) that
                // overrides erasure for the ORG data; the account itself needs support to
                // untangle. GDPR allows this: erasure requests may be resolved manually.
                return "\"{$org->name}\" has published passports with permanent public links; contact support to resolve the account.";
            }
        }

        return null;
    }

    /**
     * Erase the account. Callers must have checked blocker() first (this re-checks and throws
     * as a backstop). $initiatedBy is recorded in the audit trail ('self' or 'admin').
     */
    public function erase(User $user, string $initiatedBy, ?string $actorId = null): void
    {
        if ($message = $this->blocker($user)) {
            throw new \RuntimeException($message);
        }

        DB::transaction(function () use ($user, $initiatedBy, $actorId) {
            // Written first, inside the transaction: an erasure must itself be auditable.
            // Deliberately WITHOUT the email -- the row must not re-collect what it erases.
            AuditLog::record(
                action: 'gdpr.erasure',
                target: $user->id,
                meta: ['initiated_by' => $initiatedBy],
                actorId: $actorId ?? $user->id,
            );

            // Sole-member orgs go with the account (verified unpublished by blocker()); the
            // duplicate-registration guard matches org fields, so an orphan would block
            // re-onboarding with the same company details forever.
            foreach ($this->orgsWithCounts($user) as $org) {
                if ($org->members_count === 1) {
                    $org->delete();
                }
            }

            // Personal data keyed by email rather than FK.
            DB::table('login_codes')->where('email', $user->email)->delete();
            DB::table('invitations')->where('email', $user->email)->delete();
            DB::table('invitations')->where('invited_by', $user->id)->update(['invited_by' => null]);

            // Redact this email wherever it was denormalized into audit metadata (e.g.
            // impersonation rows record target_email/admin_email). Text-level replace on the
            // jsonb so it reaches ANY depth/shape -- nested objects, arrays, and substrings
            // inside longer values -- not just top-level exact matches (review P2). Safe on
            // the JSON encoding: platform emails are validated ASCII, never JSON-escaped.
            DB::statement(<<<'SQL'
                UPDATE audit_log
                SET meta = replace(meta::text, ?, '[erased]')::jsonb
                WHERE meta IS NOT NULL
                  AND strpos(meta::text, ?) > 0
            SQL, [$user->email, $user->email]);

            // Active database sessions for the account.
            DB::table('sessions')->where('user_id', $user->id)->delete();

            $user->delete(); // memberships cascade via FK
        });
    }

    private function orgsWithCounts(User $user)
    {
        return $user->organizations()->withCount('members')->get();
    }
}

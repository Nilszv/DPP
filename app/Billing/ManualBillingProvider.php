<?php

namespace App\Billing;

use App\Models\Organization;
use InvalidArgumentException;

/**
 * No-payment billing. Plan changes take effect immediately by updating the org's plan.
 * This lets the full plan/upgrade/quota flow work before any Stripe account exists.
 * Existing published passports are NOT unpublished on downgrade (a published DPP is a
 * long-term hosting duty); only NEW publishes are gated by the lower quota. The lapse
 * policy for published DPPs after downgrade remains an open product decision (see docs).
 */
class ManualBillingProvider implements BillingProvider
{
    public function isManual(): bool
    {
        return true;
    }

    public function changePlan(Organization $organization, string $planKey): void
    {
        if (! array_key_exists($planKey, config('billing.plans'))) {
            throw new InvalidArgumentException("Unknown plan: {$planKey}");
        }

        $organization->update(['plan' => $planKey]);
    }

    public function cancel(Organization $organization): void
    {
        $organization->update(['plan' => 'free']);
    }
}

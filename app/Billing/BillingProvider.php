<?php

namespace App\Billing;

use App\Models\Organization;

/**
 * Billing abstraction so the app never talks to a payment provider directly. The 'manual'
 * driver switches plans with no payment (used until a Stripe account exists); a future
 * StripeBillingProvider implements the same interface, so plans/quota/UI never change.
 */
interface BillingProvider
{
    /** True when no real payment is taken (manual mode). Used by the UI to show a notice. */
    public function isManual(): bool;

    /** Move the organization onto the given plan key (free|medium|commercial). */
    public function changePlan(Organization $organization, string $planKey): void;

    /** Cancel/downgrade the organization to the free plan. */
    public function cancel(Organization $organization): void;
}

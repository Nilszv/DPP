<?php

namespace App\Http\Controllers;

use App\Billing\BillingProvider;
use App\Models\Organization;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Plan management. In manual mode (no Stripe yet) a plan switch takes effect immediately.
 * Only org managers (owner/admin) may change the plan.
 */
class BillingController extends Controller
{
    public function index(BillingProvider $billing)
    {
        $org = $this->currentOrg();

        return view('app.billing.index', [
            'org' => $org,
            'plans' => Plan::where('is_public', true)->where('active', true)->orderBy('sort')->get(),
            'currency' => config('billing.currency'),
            'published' => $org->publishedCount(),
            'isManual' => $billing->isManual(),
            'canManage' => auth()->user()->canManageOrg(),
        ]);
    }

    public function switchPlan(Request $request, BillingProvider $billing)
    {
        abort_unless(auth()->user()->canManageOrg(), 403);

        // Self-service switching is limited to public, active, priced plans (free/medium).
        // Custom/contact plans (price null, e.g. commercial) are assigned by an admin.
        $selectable = Plan::where('is_public', true)->where('active', true)
            ->whereNotNull('price')->pluck('key')->all();

        $data = $request->validate(['plan' => ['required', Rule::in($selectable)]]);

        $org = $this->currentOrg();
        $target = Plan::where('key', $data['plan'])->first();

        // Block self-service downgrades that would strand already-published passports
        // (10+ year hosting duty). These must go through sales.
        if (! $org->fitsPlan($target)) {
            return back()->with('error',
                'You have '.$org->publishedCount().' published passport(s); the '.$target->name.' plan allows '
                .($target->published_quota ?? 'unlimited').'. Published passports must stay hosted, so a downgrade '
                .'has to be arranged with sales. Please use Contact sales.');
        }

        $billing->changePlan($org, $data['plan']);

        return back()->with('status', 'Plan updated to '.$target->name.'.');
    }

    private function currentOrg(): Organization
    {
        return Organization::findOrFail(app('currentOrganizationId'));
    }
}

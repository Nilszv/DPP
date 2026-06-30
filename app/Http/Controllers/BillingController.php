<?php

namespace App\Http\Controllers;

use App\Billing\BillingProvider;
use App\Models\Organization;
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
            'plans' => config('billing.plans'),
            'currency' => config('billing.currency'),
            'published' => $org->publishedCount(),
            'isManual' => $billing->isManual(),
            'canManage' => auth()->user()->canManageOrg(),
        ]);
    }

    public function switchPlan(Request $request, BillingProvider $billing)
    {
        abort_unless(auth()->user()->canManageOrg(), 403);

        $data = $request->validate([
            'plan' => ['required', Rule::in(array_keys(config('billing.plans')))],
        ]);

        $billing->changePlan($this->currentOrg(), $data['plan']);

        return back()->with('status', 'Plan updated to '.config("billing.plans.{$data['plan']}.name").'.');
    }

    private function currentOrg(): Organization
    {
        return Organization::findOrFail(app('currentOrganizationId'));
    }
}

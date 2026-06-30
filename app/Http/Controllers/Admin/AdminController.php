<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Passport;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Platform back-office: analytics overview + organization management. Super-admin only. */
class AdminController extends Controller
{
    public function overview()
    {
        return view('admin.overview', [
            'stats' => [
                'organizations' => Organization::count(),
                'users' => User::count(),
                'passports_published' => Passport::where('status', 'published')->count(),
                'passports_draft' => Passport::where('status', 'draft')->count(),
                'scans_total' => DB::table('scan_events')->count(),
                'scans_30d' => DB::table('scan_events')->where('ts', '>=', now()->subDays(30))->count(),
            ],
            'planDistribution' => Organization::select('plan', DB::raw('count(*) as total'))
                ->groupBy('plan')->pluck('total', 'plan'),
        ]);
    }

    public function organizations()
    {
        $organizations = Organization::withCount(['members', 'passports'])
            ->orderBy('created_at')->get();

        return view('admin.organizations.index', compact('organizations'));
    }

    public function editOrganization(Organization $organization)
    {
        return view('admin.organizations.edit', [
            'organization' => $organization,
            'plans' => Plan::orderBy('sort')->get(),
            'publishedCount' => $organization->publishedCount(),
        ]);
    }

    public function updateOrganization(Request $request, Organization $organization)
    {
        $data = $request->validate([
            'plan' => ['required', 'exists:plans,key'],
            'published_quota_override' => ['nullable', 'integer', 'min:0'],
            'price_override' => ['nullable', 'numeric', 'min:0'],
            'interval_override' => ['nullable', 'in:month,year'],
            'status' => ['required', 'in:active,suspended'],
        ]);

        $organization->update([
            'plan' => $data['plan'],
            'published_quota_override' => $data['published_quota_override'] ?? null,
            'price_override' => $data['price_override'] ?? null,
            'interval_override' => $data['interval_override'] ?? null,
            'status' => $data['status'],
        ]);

        return redirect()->route('admin.organizations')
            ->with('status', "Updated {$organization->name}.");
    }
}

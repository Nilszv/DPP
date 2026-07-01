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

    public function organizations(Request $request)
    {
        $query = Organization::withCount(['members', 'passports'])
            ->withCount(['passports as published_count' => fn ($q) => $q->where('status', 'published')]);

        // Search by company name / contact email / member email.
        if ($q = trim((string) $request->query('q'))) {
            $query->where(function ($w) use ($q) {
                $w->where('name', 'ILIKE', "%{$q}%")
                    ->orWhere('legal_name', 'ILIKE', "%{$q}%")
                    ->orWhere('contact_email', 'ILIKE', "%{$q}%")
                    // email is citext; cast so the (email::text) trigram index is usable.
                    ->orWhereHas('members', fn ($m) => $m->whereRaw('email::text ILIKE ?', ["%{$q}%"]));
            });
        }

        if ($plan = $request->query('plan')) {
            $query->where('plan', $plan);
        }
        if (in_array($request->query('status'), ['active', 'suspended'], true)) {
            $query->where('status', $request->query('status'));
        }

        // Sort.
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';
        $column = match ($request->query('sort')) {
            'name' => 'name',
            'published' => 'published_count',
            default => 'created_at',
        };
        $query->orderBy($column, $dir);

        return view('admin.organizations.index', [
            'organizations' => $query->paginate(20)->withQueryString(),
            'plans' => Plan::orderBy('sort')->get(),
            'filters' => [
                'q' => $request->query('q'),
                'plan' => $request->query('plan'),
                'status' => $request->query('status'),
                'sort' => $request->query('sort', 'created'),
                'dir' => $dir,
            ],
        ]);
    }

    public function showOrganization(Organization $organization)
    {
        $organization->load('members');

        return view('admin.organizations.show', [
            'organization' => $organization,
            'publishedCount' => $organization->publishedCount(),
            'draftCount' => $organization->passports()->where('status', 'draft')->count(),
            'acceptances' => $organization->legalAcceptances()->with('user:id,name,email')
                ->orderByDesc('accepted_at')->get(),
        ]);
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
            'team_quota_override' => ['nullable', 'integer', 'min:1'],
            'price_override' => ['nullable', 'numeric', 'min:0'],
            'interval_override' => ['nullable', 'in:month,year'],
            'status' => ['required', 'in:active,suspended'],
        ]);

        $organization->update([
            'plan' => $data['plan'],
            'published_quota_override' => $data['published_quota_override'] ?? null,
            'team_quota_override' => $data['team_quota_override'] ?? null,
            'price_override' => $data['price_override'] ?? null,
            'interval_override' => $data['interval_override'] ?? null,
            'status' => $data['status'],
        ]);

        return redirect()->route('admin.organizations')
            ->with('status', "Updated {$organization->name}.");
    }

    /**
     * Lift a user-level suspension (e.g. after resolving a duplicate-registration case) and
     * reset the blocked-attempt counter so the account gets a clean slate.
     */
    public function unsuspendUser(User $user)
    {
        $user->forceFill([
            'suspended_at' => null,
            'suspension_reason' => null,
            'duplicate_onboarding_attempts' => 0,
        ])->save();

        return back()->with('status', "Lifted suspension for {$user->email}.");
    }

    /**
     * Delete a user (support/testing tool, e.g. to retest onboarding from scratch). Also deletes
     * any organization where they are the sole member: the duplicate-registration guard matches
     * on organization fields (name/registration/VAT), not the user, so an orphaned org would
     * still block re-onboarding with the same company details. Refuses to touch a sole-member
     * org that has published passports -- published DPPs carry a permanent public resolver link
     * (GS1 / /p/{uuid}), so this tool must never be able to break one.
     */
    public function deleteUser(User $user)
    {
        if (auth()->id() === $user->id) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        $orgs = $user->organizations()->withCount('members')->get();

        foreach ($orgs as $org) {
            $isSoleOwner = $org->pivot->role === 'owner'
                && $org->members()->wherePivot('role', 'owner')->count() === 1;

            if ($org->members_count > 1 && $isSoleOwner) {
                return back()->with('error', "Cannot delete: sole owner of \"{$org->name}\", which has other members. Reassign ownership first.");
            }

            if ($org->members_count === 1 && $org->publishedCount() > 0) {
                return back()->with('error', "Cannot delete: \"{$org->name}\" has published passports with permanent public links. Those cannot be deleted with the org; resolve them first.");
            }
        }

        DB::transaction(function () use ($user, $orgs) {
            foreach ($orgs as $org) {
                if ($org->members_count === 1) {
                    $org->delete();
                }
            }

            $user->delete();
        });

        return redirect()->route('admin.organizations')->with('status', "Deleted user {$user->email}.");
    }
}

<?php

namespace App\Http\Controllers;

use App\Mail\TeamInviteMail;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Team / member management for the current organization. Owner/admin only for changes. */
class TeamController extends Controller
{
    private const INVITE_TTL_DAYS = 7;

    public function index()
    {
        $org = $this->currentOrg();

        return view('app.team.index', [
            'org' => $org,
            'members' => $org->members()->orderBy('name')->get(),
            'invitations' => $org->pendingInvitations()->orderBy('email')->get(),
            'canManage' => auth()->user()->canManageOrg(),
            'seatLimit' => $org->seatLimit(),
            'usedSeats' => $org->usedSeats(),
        ]);
    }

    public function invite(Request $request)
    {
        abort_unless(auth()->user()->canManageOrg(), 403);
        $org = $this->currentOrg();

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(['admin', 'editor', 'viewer'])],
        ]);
        $email = strtolower(trim($data['email']));

        // Serialize seat checks + invitation writes per org so two concurrent invites cannot
        // both pass the seat check. Mail is sent AFTER the transaction commits.
        $outcome = DB::transaction(function () use ($org, $email, $data) {
            $this->lockOrg($org);

            if ($org->members()->where('email', $email)->exists()) {
                return ['error' => 'That person is already a member.'];
            }
            if ($org->pendingInvitations()->where('email', $email)->exists()) {
                return ['error' => 'That email already has a pending invitation.'];
            }
            if (! $org->hasSeatAvailable()) {
                return ['error' => 'You have used all '.$org->seatLimit().' seats on your plan. Upgrade or contact sales for more.'];
            }

            // Drop any stale (expired, unaccepted) invite so the partial unique index is free.
            $org->invitations()->where('email', $email)->whereNull('accepted_at')->delete();

            $invitation = Invitation::create([
                'organization_id' => $org->id,
                'email' => $email,
                'role' => $data['role'],
                'token' => Str::random(48),
                'invited_by' => auth()->id(),
                'expires_at' => Carbon::now()->addDays(self::INVITE_TTL_DAYS),
            ]);

            return ['invitation' => $invitation];
        });

        if (isset($outcome['error'])) {
            return back()->with('error', $outcome['error']);
        }

        Mail::to($email)->send(new TeamInviteMail(
            $outcome['invitation'],
            route('invitations.show', $outcome['invitation']->token),
        ));

        return back()->with('status', 'Invitation sent to '.$email.'.');
    }

    public function updateRole(Request $request, User $member)
    {
        abort_unless(auth()->user()->canManageOrg(), 403);
        $org = $this->currentOrg();
        $this->assertMember($org, $member);

        $data = $request->validate(['role' => ['required', Rule::in(['owner', 'admin', 'editor', 'viewer'])]]);

        $outcome = DB::transaction(function () use ($org, $member, $data) {
            $this->lockOrg($org);

            // Never leave the org without an owner.
            if ($this->isLastOwner($org, $member) && $data['role'] !== 'owner') {
                return ['error' => 'You cannot change the role of the last owner.'];
            }

            $org->members()->updateExistingPivot($member->id, ['role' => $data['role']]);

            return ['ok' => true];
        });

        return isset($outcome['error'])
            ? back()->with('error', $outcome['error'])
            : back()->with('status', 'Role updated.');
    }

    public function removeMember(User $member)
    {
        abort_unless(auth()->user()->canManageOrg(), 403);
        $org = $this->currentOrg();
        $this->assertMember($org, $member);

        $outcome = DB::transaction(function () use ($org, $member) {
            $this->lockOrg($org);

            if ($this->isLastOwner($org, $member)) {
                return ['error' => 'You cannot remove the last owner.'];
            }

            $org->members()->detach($member->id);

            return ['ok' => true];
        });

        if (isset($outcome['error'])) {
            return back()->with('error', $outcome['error']);
        }

        // Repair the removed member's current org pointer if it pointed here.
        if ($member->current_organization_id === $org->id) {
            $member->forceFill(['current_organization_id' => $member->currentOrganizationIdIfMember()])->save();
        }

        return back()->with('status', 'Member removed.');
    }

    public function revokeInvitation(Invitation $invitation)
    {
        abort_unless(auth()->user()->canManageOrg(), 403);
        abort_unless($invitation->organization_id === app('currentOrganizationId'), 404);

        $invitation->delete();

        return back()->with('status', 'Invitation revoked.');
    }

    private function currentOrg(): Organization
    {
        return Organization::findOrFail(app('currentOrganizationId'));
    }

    /** Per-org advisory transaction lock (classid 2 = team ops; distinct from publish/login). */
    private function lockOrg(Organization $org): void
    {
        DB::statement('SELECT pg_advisory_xact_lock(?, hashtext(?))', [2, $org->id]);
    }

    private function assertMember(Organization $org, User $member): void
    {
        abort_unless($org->members()->whereKey($member->id)->exists(), 404);
    }

    private function isLastOwner(Organization $org, User $member): bool
    {
        $isOwner = $org->members()->wherePivot('role', 'owner')->whereKey($member->id)->exists();

        return $isOwner && $org->members()->wherePivot('role', 'owner')->count() === 1;
    }
}

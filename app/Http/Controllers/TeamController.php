<?php

namespace App\Http\Controllers;

use App\Mail\TeamInviteMail;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

        if (! $org->hasSeatAvailable()) {
            return back()->with('error', 'You have used all '.$org->seatLimit().' seats on your plan. Upgrade or contact sales for more.');
        }
        if ($org->members()->where('email', $email)->exists()) {
            return back()->with('error', 'That person is already a member.');
        }
        if ($org->pendingInvitations()->where('email', $email)->exists()) {
            return back()->with('error', 'That email already has a pending invitation.');
        }

        $invitation = Invitation::create([
            'organization_id' => $org->id,
            'email' => $email,
            'role' => $data['role'],
            'token' => Str::random(48),
            'invited_by' => auth()->id(),
            'expires_at' => Carbon::now()->addDays(self::INVITE_TTL_DAYS),
        ]);

        Mail::to($email)->send(new TeamInviteMail(
            $invitation,
            route('invitations.show', $invitation->token),
        ));

        return back()->with('status', 'Invitation sent to '.$email.'.');
    }

    public function updateRole(Request $request, User $member)
    {
        abort_unless(auth()->user()->canManageOrg(), 403);
        $org = $this->currentOrg();
        $this->assertMember($org, $member);

        $data = $request->validate(['role' => ['required', Rule::in(['owner', 'admin', 'editor', 'viewer'])]]);

        // Never leave the org without an owner.
        if ($this->isLastOwner($org, $member) && $data['role'] !== 'owner') {
            return back()->with('error', 'You cannot change the role of the last owner.');
        }

        $org->members()->updateExistingPivot($member->id, ['role' => $data['role']]);

        return back()->with('status', 'Role updated.');
    }

    public function removeMember(User $member)
    {
        abort_unless(auth()->user()->canManageOrg(), 403);
        $org = $this->currentOrg();
        $this->assertMember($org, $member);

        if ($this->isLastOwner($org, $member)) {
            return back()->with('error', 'You cannot remove the last owner.');
        }

        $org->members()->detach($member->id);

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

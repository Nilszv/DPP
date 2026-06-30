<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Accepting a team invitation. Auth-only (not behind onboarding) so a brand-new invitee can
 * accept before dealing with their own personal org. Only the invited email may accept.
 */
class InvitationController extends Controller
{
    public function show(Request $request, string $token)
    {
        $invitation = $this->valid($token);

        return view('app.invitations.show', [
            'invitation' => $invitation,
            'matchesEmail' => $invitation && strtolower($request->user()->email) === $invitation->email,
        ]);
    }

    public function accept(Request $request, string $token)
    {
        $invitation = $this->valid($token);
        abort_if(! $invitation, 410, 'This invitation is no longer valid.');

        // Only the invited email may accept.
        abort_unless(strtolower($request->user()->email) === $invitation->email, 403,
            'This invitation was sent to a different email address.');

        $org = $invitation->organization;
        $user = $request->user();

        // Serialize per org so two concurrent accepts cannot both pass the seat check.
        DB::transaction(function () use ($org, $user, $invitation) {
            DB::statement('SELECT pg_advisory_xact_lock(?, hashtext(?))', [2, $org->id]);

            if (! $org->members()->whereKey($user->id)->exists()) {
                abort_unless($org->hasSeatAvailable(), 403, 'This organization has no seats available.');
                $org->members()->attach($user->id, ['role' => $invitation->role]);
            }

            $invitation->update(['accepted_at' => now()]);
            $user->forceFill(['current_organization_id' => $org->id])->save();
        });

        return redirect()->route('dashboard')->with('status', 'You have joined '.$org->name.'.');
    }

    /** A pending, unexpired invitation for the token, or null. */
    private function valid(string $token): ?Invitation
    {
        return Invitation::where('token', $token)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();
    }
}

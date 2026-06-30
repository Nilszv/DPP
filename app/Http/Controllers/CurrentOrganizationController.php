<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/** Switches the user's active organization (for users who belong to more than one). */
class CurrentOrganizationController extends Controller
{
    public function switch(Request $request)
    {
        $data = $request->validate(['organization_id' => ['required', 'string']]);
        $user = $request->user();

        // Only switch to an org the user is actually a member of.
        abort_unless($user->organizations()->whereKey($data['organization_id'])->exists(), 403);

        $user->forceFill(['current_organization_id' => $data['organization_id']])->save();

        return redirect()->route('dashboard');
    }
}

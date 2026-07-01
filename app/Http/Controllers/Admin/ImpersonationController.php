<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Admin "log in as" a user, audited. Starting one requires a FRESH 2FA code (not just the
 * session's existing 2fa.passed flag) immediately before the swap, given how sensitive it is.
 * Only a regular (non-admin) user can ever be a target -- an admin can never impersonate
 * another admin, or themselves.
 */
class ImpersonationController extends Controller
{
    public function __construct(private TwoFactorService $twoFactor) {}

    public function start(Request $request, User $user): RedirectResponse
    {
        if ($error = $this->guardTarget($request, $user)) {
            return back()->with('error', $error);
        }

        $request->session()->put('impersonate.target_id', $user->id);
        $request->session()->put('impersonate.return_to', url()->previous());

        return redirect()->route('admin.impersonate.confirm');
    }

    public function showConfirm(Request $request): View|RedirectResponse
    {
        $target = $this->pendingTarget($request);
        if (! $target) {
            return redirect()->route('admin.organizations');
        }

        return view('admin.impersonate-confirm', ['target' => $target]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $target = $this->pendingTarget($request);
        if (! $target) {
            return redirect()->route('admin.organizations');
        }

        // Re-check fresh, not anything cached from start(): the target's admin status (or the
        // acting admin's own identity) could have changed in the gap between the two requests.
        if ($error = $this->guardTarget($request, $target)) {
            $request->session()->forget('impersonate.target_id');

            return redirect()->route('admin.organizations')->with('error', $error);
        }

        $admin = $request->user();

        if ($this->twoFactor->tooManyAttempts($admin)) {
            $seconds = $this->twoFactor->availableInSeconds($admin);

            return back()->withErrors(['code' => "Too many failed attempts. Try again in {$seconds} seconds."]);
        }

        $request->validate([
            'code' => ['nullable', 'digits:6'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        if (! $this->twoFactor->attemptVerification($admin, $request->input('code'), $request->input('recovery_code'))) {
            return back()->withErrors(['code' => 'That code is invalid or has expired. Please try again.']);
        }

        $originalAdminId = $admin->id;

        // Written BEFORE the swap: actor_id must still resolve to the admin, not the target.
        AuditLog::record(
            action: 'impersonation.started',
            target: $target->id,
            meta: ['target_email' => $target->email, 'admin_email' => $admin->email],
            actorId: $originalAdminId,
            organizationId: $target->currentOrganizationIdIfMember(),
        );

        $request->session()->forget('impersonate.target_id');

        Auth::loginUsingId($target->id);
        $request->session()->regenerate();
        $request->session()->put('impersonate.original_admin_id', $originalAdminId);

        return redirect()->route('dashboard');
    }

    public function stop(Request $request): RedirectResponse
    {
        $originalAdminId = $request->session()->get('impersonate.original_admin_id');
        if (! $originalAdminId) {
            return redirect()->route('dashboard');
        }

        $impersonatedUser = $request->user();

        // actor_id comes from the stashed session value, not Auth::id() -- Auth::id() here
        // still resolves to the impersonated user until loginUsingId() below runs.
        AuditLog::record(
            action: 'impersonation.ended',
            target: $impersonatedUser->id,
            meta: ['target_email' => $impersonatedUser->email],
            actorId: $originalAdminId,
            organizationId: $impersonatedUser->currentOrganizationIdIfMember(),
        );

        $returnTo = $request->session()->pull('impersonate.return_to', route('admin.overview'));
        $request->session()->forget('impersonate.original_admin_id');

        Auth::loginUsingId($originalAdminId);
        $request->session()->regenerate();

        return redirect()->to($returnTo);
    }

    /** Null if this target is safe to impersonate; otherwise the error message to show. */
    private function guardTarget(Request $request, User $target): ?string
    {
        if ($request->user()->id === $target->id) {
            return 'You cannot impersonate yourself.';
        }

        if ($target->isAdmin()) {
            return 'You cannot impersonate another admin.';
        }

        return null;
    }

    private function pendingTarget(Request $request): ?User
    {
        $id = $request->session()->get('impersonate.target_id');

        return $id ? User::find($id) : null;
    }
}

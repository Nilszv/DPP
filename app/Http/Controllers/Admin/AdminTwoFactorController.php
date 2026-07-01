<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Self-service 2FA management for the currently signed-in admin. */
class AdminTwoFactorController extends Controller
{
    public function __construct(private TwoFactorService $twoFactor) {}

    public function show(Request $request): View
    {
        $user = $request->user();

        return view('admin.security', [
            'confirmedAt' => $user->two_factor_confirmed_at,
            'recoveryCodesRemaining' => count($user->two_factor_recovery_codes ?? []),
            'recoveryCodesOnce' => $request->session()->get('2fa.recovery_codes_once'),
        ]);
    }

    public function regenerateRecoveryCodes(Request $request): RedirectResponse
    {
        $codes = $this->twoFactor->regenerateRecoveryCodes($request->user());
        $request->session()->flash('2fa.recovery_codes_once', $codes);

        return redirect()->route('admin.security.show')
            ->with('status', 'New recovery codes generated. The old ones no longer work.');
    }

    /** Requires a fresh valid TOTP code as step-up confirmation before ripping out 2FA. */
    public function reset(Request $request): RedirectResponse
    {
        $user = $request->user();
        $request->validate(['code' => ['required', 'digits:6']]);

        if (! $this->twoFactor->verifyCode($user->two_factor_secret, $request->input('code'))) {
            return back()->withErrors(['code' => 'That code is invalid. Please try again.']);
        }

        $this->twoFactor->reset($user);
        // Without this, the admin keeps full /admin access with no 2FA configured until their
        // session naturally expires -- the middleware only checks the session flag.
        $request->session()->forget('2fa.passed');

        return redirect()->route('2fa.setup')
            ->with('status', '2FA has been reset. Set it up again to continue.');
    }
}

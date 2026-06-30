<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Mail\LoginCodeMail;
use App\Services\LoginCodeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PasswordlessController extends Controller
{
    public function __construct(private LoginCodeService $codes) {}

    /** Step 1: email entry form. */
    public function showLogin(): View
    {
        return view('auth.login');
    }

    /** Step 2: issue + email a code, then show the code-entry form. */
    public function sendCode(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $email = strtolower(trim($data['email']));

        $code = $this->codes->issue($email);
        Mail::to($email)->send(new LoginCodeMail($code, LoginCodeService::EXPIRY_MINUTES));

        // Remember which email we are verifying (not whether it has an account).
        $request->session()->put('login.email', $email);

        return redirect()->route('login.code')
            ->with('status', 'We sent a 6-digit code to ' . $email . '. It expires in '
                . LoginCodeService::EXPIRY_MINUTES . ' minutes.');
    }

    /** Step 3: code-entry form (requires a pending email in session). */
    public function showVerify(Request $request): View|RedirectResponse
    {
        $email = $request->session()->get('login.email');
        if (! $email) {
            return redirect()->route('login');
        }

        return view('auth.verify', ['email' => $email]);
    }

    /** Step 4: verify the code; create the account if new, then log in. */
    public function verify(Request $request): RedirectResponse
    {
        $email = $request->session()->get('login.email');
        if (! $email) {
            return redirect()->route('login');
        }

        $request->validate(['code' => ['required', 'digits:6']]);

        if (! $this->codes->verify($email, $request->input('code'))) {
            return back()->withErrors(['code' => 'That code is invalid or has expired. Please try again.']);
        }

        $user = $this->findOrCreateUser($email);

        Auth::login($user, remember: true);
        $request->session()->regenerate();
        $request->session()->forget('login.email');

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /**
     * Find the user by email, or create the user plus their first organization
     * (they become its owner). First login == account creation.
     */
    private function findOrCreateUser(string $email): User
    {
        $user = User::where('email', $email)->first();
        if ($user) {
            return $user;
        }

        $name = Str::of($email)->before('@')->headline()->toString();

        $user = User::create([
            'name' => $name ?: 'New user',
            'email' => $email,
            'email_verified_at' => now(),   // verified by the code itself
        ]);

        $org = Organization::create([
            'name' => $name . "'s organization",
            'slug' => Str::slug(Str::before($email, '@')) . '-' . Str::lower(Str::random(6)),
            'plan' => 'free',
            'status' => 'active',
        ]);

        $org->members()->attach($user->id, ['role' => 'owner']);
        $user->update(['current_organization_id' => $org->id]);

        return $user;
    }
}

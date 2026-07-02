<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\AdminLegalController;
use App\Http\Controllers\Admin\AdminPassportController;
use App\Http\Controllers\Admin\AdminPlanController;
use App\Http\Controllers\Admin\AdminTwoFactorController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Auth\PasswordlessController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\CurrentOrganizationController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\PassportController;
use App\Http\Controllers\ResolverController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\TeamController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Lightweight service health check (DB connectivity). Plain, no auth.
Route::get('/health', function () {
    try {
        DB::connection()->getPdo();
        $db = 'ok';
    } catch (Throwable $e) {
        $db = 'error';
    }

    return response()->json([
        'service' => 'dpp-platform',
        'status' => $db === 'ok' ? 'ok' : 'degraded',
        'database' => $db,
        'driver' => config('database.default'),
    ], $db === 'ok' ? 200 : 503);
});

// ---- Passwordless auth (guest) ----
Route::middleware('guest')->group(function () {
    Route::get('/login', [PasswordlessController::class, 'showLogin'])->name('login');

    // Throttle code requests: 5 per minute per IP (per-email cap is enforced by single-live-code).
    Route::post('/login', [PasswordlessController::class, 'sendCode'])
        ->middleware('throttle:5,1')->name('login.send');

    Route::get('/login/code', [PasswordlessController::class, 'showVerify'])->name('login.code');

    // Throttle verify attempts: 10 per minute per IP (plus the 5-attempt cap per code).
    Route::post('/login/code', [PasswordlessController::class, 'verify'])
        ->middleware('throttle:10,1')->name('login.verify');
});

// Logout only needs auth (a suspended-org user must still be able to log out).
Route::post('/logout', [PasswordlessController::class, 'logout'])->middleware('auth')->name('logout');

// End an active impersonation. Bare 'auth' only: must be reachable regardless of the
// impersonated user's onboarding/suspension/org state, and needs no org context of its own.
Route::post('/impersonate/stop', [ImpersonationController::class, 'stop'])
    ->middleware('auth')->name('impersonate.stop');

// ---- Admin TOTP two-factor: pending-login setup/verify. Reachable mid-login for an admin
// (after the primary email code, before Auth::login()) -- neither pure 'guest' nor 'auth' fits,
// so gating is done inside the controller via session('2fa.pending_user_id'). ----
Route::get('/login/2fa/setup', [TwoFactorController::class, 'showSetup'])->name('2fa.setup');
Route::post('/login/2fa/setup', [TwoFactorController::class, 'confirmSetup'])
    ->middleware('throttle:10,1')->name('2fa.setup.confirm');

Route::get('/login/2fa/verify', [TwoFactorController::class, 'showVerify'])->name('2fa.verify');
Route::post('/login/2fa/verify', [TwoFactorController::class, 'verify'])
    ->middleware('throttle:10,1')->name('2fa.verify.confirm');

// A remember-me-revived (or otherwise stale) admin session that never passed 2FA this session.
Route::middleware('auth')->group(function () {
    Route::get('/2fa/reverify', [TwoFactorController::class, 'showVerify'])->name('2fa.reverify');
    Route::post('/2fa/reverify', [TwoFactorController::class, 'verify'])
        ->middleware('throttle:10,1')->name('2fa.reverify.confirm');
    Route::get('/2fa/recovery-codes', [TwoFactorController::class, 'showRecoveryCodes'])->name('2fa.recovery-codes');
});

// Accept a team invitation: auth-only (a new invitee can accept before onboarding their own org).
Route::middleware('auth')->group(function () {
    Route::get('/app/invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
    Route::post('/app/invitations/{token}/accept', [InvitationController::class, 'accept'])->name('invitations.accept');
});

// Switch active organization (available without completing onboarding of the current org).
Route::post('/app/switch-org', [CurrentOrganizationController::class, 'switch'])
    ->middleware(['auth', 'org.context'])->name('current-org.switch');

// Support / contact page. Auth-only and NOT behind 'not.suspended' on purpose: a suspended
// account must still be able to reach support (and log out) to get help.
Route::middleware('auth')->group(function () {
    Route::get('/app/support', [SupportController::class, 'show'])->name('support.show');
    Route::post('/app/support', [SupportController::class, 'send'])
        ->middleware('throttle:5,1')->name('support.send');
});

// First-run onboarding (reachable before the org is onboarded; not behind 'onboarded').
Route::middleware(['auth', 'org.context', 'org.active', 'not.suspended'])->group(function () {
    Route::get('/app/onboarding', [OnboardingController::class, 'show'])->name('onboarding.show');
    Route::post('/app/onboarding', [OnboardingController::class, 'store'])->name('onboarding.store');
});

// ---- Authenticated platform (/app), requires a completed onboarding ----
Route::middleware(['auth', 'org.context', 'org.active', 'not.suspended', 'onboarded'])->group(function () {
    Route::get('/app', function () {
        return view('app.dashboard');
    })->name('dashboard');

    // Organization profile (company data captured at onboarding).
    Route::get('/app/organization', [OrganizationController::class, 'show'])->name('organization.show');
    Route::put('/app/organization', [OrganizationController::class, 'update'])->name('organization.update');

    // Team / members.
    Route::get('/app/team', [TeamController::class, 'index'])->name('team.index');
    Route::post('/app/team/invite', [TeamController::class, 'invite'])->name('team.invite');
    Route::put('/app/team/members/{member}', [TeamController::class, 'updateRole'])->name('team.members.role');
    Route::delete('/app/team/members/{member}', [TeamController::class, 'removeMember'])->name('team.members.remove');
    Route::delete('/app/team/invitations/{invitation}', [TeamController::class, 'revokeInvitation'])->name('team.invitations.revoke');

    // Passport lifecycle: list, create draft, edit fields, view, publish, QR.
    Route::get('/app/passports', [PassportController::class, 'index'])->name('passports.index');
    Route::get('/app/passports/create', [PassportController::class, 'create'])->name('passports.create');
    Route::post('/app/passports', [PassportController::class, 'store'])->name('passports.store');
    Route::get('/app/passports/{passport}', [PassportController::class, 'show'])->name('passports.show');
    Route::get('/app/passports/{passport}/edit', [PassportController::class, 'edit'])->name('passports.edit');
    Route::put('/app/passports/{passport}', [PassportController::class, 'update'])->name('passports.update');
    Route::post('/app/passports/{passport}/publish', [PassportController::class, 'publish'])->name('passports.publish');
    // Post-publish corrections: open a draft version, publish it (swaps the public data), or discard it.
    Route::post('/app/passports/{passport}/corrections', [PassportController::class, 'startCorrection'])->name('passports.corrections.start');
    Route::post('/app/passports/{passport}/corrections/publish', [PassportController::class, 'publishCorrection'])->name('passports.corrections.publish');
    Route::delete('/app/passports/{passport}/corrections', [PassportController::class, 'discardCorrection'])->name('passports.corrections.discard');
    Route::get('/app/passports/{passport}/qr', [PassportController::class, 'qr'])->name('passports.qr');
    Route::post('/app/passports/{passport}/tiers/{audience}/regenerate', [PassportController::class, 'regenerateTier'])
        ->whereIn('audience', ['repairer', 'recycler', 'authority'])->name('passports.tiers.regenerate');

    // Plan & billing (manual mode until Stripe is configured).
    Route::get('/app/billing', [BillingController::class, 'index'])->name('billing.index');
    Route::post('/app/billing/switch', [BillingController::class, 'switchPlan'])->name('billing.switch');

    // Contact sales (downgrade requests / custom plans) -> sales inbox.
    Route::post('/app/contact-sales', [ContactController::class, 'sendSales'])
        ->middleware('throttle:5,1')->name('contact.sales');
});

// ---- Platform back-office (super-admin only) ----
Route::middleware(['auth', 'admin', 'admin.2fa'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminController::class, 'overview'])->name('overview');

    Route::get('/organizations', [AdminController::class, 'organizations'])->name('organizations');
    Route::get('/organizations/{organization}', [AdminController::class, 'showOrganization'])->name('organizations.show');
    Route::get('/organizations/{organization}/edit', [AdminController::class, 'editOrganization'])->name('organizations.edit');
    Route::put('/organizations/{organization}', [AdminController::class, 'updateOrganization'])->name('organizations.update');

    // Lift a user-level suspension (e.g. after resolving a duplicate-registration case).
    Route::post('/users/{user}/unsuspend', [AdminController::class, 'unsuspendUser'])->name('users.unsuspend');

    // Delete a user (support/testing tool, e.g. to retest onboarding from scratch).
    Route::delete('/users/{user}', [AdminController::class, 'deleteUser'])->name('users.delete');

    Route::get('/passports', [AdminPassportController::class, 'index'])->name('passports.index');
    Route::get('/passports/{passport}/qr', [AdminPassportController::class, 'qr'])->name('passports.qr');

    // Read-only audit-trail browser (impersonations, correction publishes, ...).
    Route::get('/audit', [AuditLogController::class, 'index'])->name('audit.index');

    Route::get('/legal', [AdminLegalController::class, 'index'])->name('legal.index');
    Route::get('/legal/{document}/edit', [AdminLegalController::class, 'edit'])->name('legal.edit');
    Route::put('/legal/{document}', [AdminLegalController::class, 'update'])->name('legal.update');

    Route::get('/plans', [AdminPlanController::class, 'index'])->name('plans.index');
    Route::get('/plans/create', [AdminPlanController::class, 'create'])->name('plans.create');
    Route::post('/plans', [AdminPlanController::class, 'store'])->name('plans.store');
    Route::get('/plans/{plan}/edit', [AdminPlanController::class, 'edit'])->name('plans.edit');
    Route::put('/plans/{plan}', [AdminPlanController::class, 'update'])->name('plans.update');

    Route::get('/security', [AdminTwoFactorController::class, 'show'])->name('security.show');
    Route::post('/security/recovery-codes', [AdminTwoFactorController::class, 'regenerateRecoveryCodes'])
        ->name('security.recovery-codes.regenerate');
    Route::post('/security/reset', [AdminTwoFactorController::class, 'reset'])->name('security.reset');

    // "Log in as" a user, audited, gated by a fresh 2FA step-up immediately before the swap.
    // The literal /impersonate/confirm routes must be registered BEFORE the /impersonate/{user}
    // wildcard, or Laravel matches "confirm" as a {user} route-model-binding attempt first.
    Route::get('/impersonate/confirm', [ImpersonationController::class, 'showConfirm'])->name('impersonate.confirm');
    Route::post('/impersonate/confirm', [ImpersonationController::class, 'confirm'])
        ->middleware('throttle:10,1')->name('impersonate.confirm.submit');
    Route::post('/impersonate/{user}', [ImpersonationController::class, 'start'])->name('impersonate.start');
});

// ---- Public passport resolver (QR scan target, no auth) ----
// GS1 Digital Link form: /01/{gtin}/21/{serial} and GTIN-only /01/{gtin}.
Route::get('/01/{gtin}/21/{serial}', [ResolverController::class, 'showByGs1'])->name('passport.gs1');
Route::get('/01/{gtin}', [ResolverController::class, 'showByGs1'])->name('passport.gs1.gtin');
// Fallback opaque id: /p/{public_id}.
Route::get('/p/{publicId}', [ResolverController::class, 'showByPublicId'])->name('passport.public');
// Tiered access link: /p/{public_id}/{audience}/{token} (repairer/recycler/authority).
Route::get('/p/{publicId}/{audience}/{token}', [ResolverController::class, 'showByTier'])
    ->whereIn('audience', ['repairer', 'recycler', 'authority'])->name('passport.tier');

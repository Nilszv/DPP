<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\AdminLegalController;
use App\Http\Controllers\Admin\AdminPassportController;
use App\Http\Controllers\Admin\AdminPlanController;
use App\Http\Controllers\Auth\PasswordlessController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\PassportController;
use App\Http\Controllers\ResolverController;
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

// First-run onboarding (reachable before the org is onboarded; not behind 'onboarded').
Route::middleware(['auth', 'org.context', 'org.active'])->group(function () {
    Route::get('/app/onboarding', [OnboardingController::class, 'show'])->name('onboarding.show');
    Route::post('/app/onboarding', [OnboardingController::class, 'store'])->name('onboarding.store');
});

// ---- Authenticated platform (/app), requires a completed onboarding ----
Route::middleware(['auth', 'org.context', 'org.active', 'onboarded'])->group(function () {
    Route::get('/app', function () {
        return view('app.dashboard');
    })->name('dashboard');

    // Organization profile (company data captured at onboarding).
    Route::get('/app/organization', [OrganizationController::class, 'show'])->name('organization.show');
    Route::put('/app/organization', [OrganizationController::class, 'update'])->name('organization.update');

    // Passport lifecycle: list, create draft, edit fields, view, publish, QR.
    Route::get('/app/passports', [PassportController::class, 'index'])->name('passports.index');
    Route::get('/app/passports/create', [PassportController::class, 'create'])->name('passports.create');
    Route::post('/app/passports', [PassportController::class, 'store'])->name('passports.store');
    Route::get('/app/passports/{passport}', [PassportController::class, 'show'])->name('passports.show');
    Route::get('/app/passports/{passport}/edit', [PassportController::class, 'edit'])->name('passports.edit');
    Route::put('/app/passports/{passport}', [PassportController::class, 'update'])->name('passports.update');
    Route::post('/app/passports/{passport}/publish', [PassportController::class, 'publish'])->name('passports.publish');
    Route::get('/app/passports/{passport}/qr', [PassportController::class, 'qr'])->name('passports.qr');

    // Plan & billing (manual mode until Stripe is configured).
    Route::get('/app/billing', [BillingController::class, 'index'])->name('billing.index');
    Route::post('/app/billing/switch', [BillingController::class, 'switchPlan'])->name('billing.switch');

    // Contact sales (downgrade requests / custom plans) -> sales inbox.
    Route::post('/app/contact-sales', [ContactController::class, 'sendSales'])
        ->middleware('throttle:5,1')->name('contact.sales');
});

// ---- Platform back-office (super-admin only) ----
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminController::class, 'overview'])->name('overview');

    Route::get('/organizations', [AdminController::class, 'organizations'])->name('organizations');
    Route::get('/organizations/{organization}', [AdminController::class, 'showOrganization'])->name('organizations.show');
    Route::get('/organizations/{organization}/edit', [AdminController::class, 'editOrganization'])->name('organizations.edit');
    Route::put('/organizations/{organization}', [AdminController::class, 'updateOrganization'])->name('organizations.update');

    Route::get('/passports', [AdminPassportController::class, 'index'])->name('passports.index');
    Route::get('/passports/{passport}/qr', [AdminPassportController::class, 'qr'])->name('passports.qr');

    Route::get('/legal', [AdminLegalController::class, 'index'])->name('legal.index');
    Route::get('/legal/{document}/edit', [AdminLegalController::class, 'edit'])->name('legal.edit');
    Route::put('/legal/{document}', [AdminLegalController::class, 'update'])->name('legal.update');

    Route::get('/plans', [AdminPlanController::class, 'index'])->name('plans.index');
    Route::get('/plans/create', [AdminPlanController::class, 'create'])->name('plans.create');
    Route::post('/plans', [AdminPlanController::class, 'store'])->name('plans.store');
    Route::get('/plans/{plan}/edit', [AdminPlanController::class, 'edit'])->name('plans.edit');
    Route::put('/plans/{plan}', [AdminPlanController::class, 'update'])->name('plans.update');
});

// ---- Public passport resolver (QR scan target, no auth) ----
// GS1 Digital Link form: /01/{gtin}/21/{serial} and GTIN-only /01/{gtin}.
Route::get('/01/{gtin}/21/{serial}', [ResolverController::class, 'showByGs1'])->name('passport.gs1');
Route::get('/01/{gtin}', [ResolverController::class, 'showByGs1'])->name('passport.gs1.gtin');
// Fallback opaque id: /p/{public_id}.
Route::get('/p/{publicId}', [ResolverController::class, 'showByPublicId'])->name('passport.public');

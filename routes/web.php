<?php

use App\Http\Controllers\Auth\PasswordlessController;
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
    } catch (\Throwable $e) {
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

// ---- Authenticated platform (/app) ----
Route::middleware(['auth', 'org.context'])->group(function () {
    Route::post('/logout', [PasswordlessController::class, 'logout'])->name('logout');

    Route::get('/app', function () {
        return view('app.dashboard');
    })->name('dashboard');
});

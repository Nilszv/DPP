<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /**
     * Act as an admin with 2FA already confirmed AND this session flagged as having passed it
     * -- admin routes require BOTH (EnsureAdminTwoFactorVerified), and actingAs() bypasses the
     * login controller entirely, so neither is ever set on its own. Returns $this (like
     * actingAs()) so it chains directly into a request; pass an existing User in if the caller
     * needs to keep a reference to it (e.g. to use as a route parameter).
     */
    protected function actingAsAdmin(?User $admin = null): static
    {
        $admin ??= User::create([
            'name' => 'Admin', 'email' => 'admin.'.Str::lower(Str::random(5)).'@example.com',
            'email_verified_at' => now(),
        ]);
        $admin->forceFill(['is_admin' => true, 'two_factor_confirmed_at' => now()])->save();

        $this->actingAs($admin)->withSession(['2fa.passed' => true]);

        return $this;
    }
}

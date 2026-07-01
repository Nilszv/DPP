<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP two-factor auth for admin users. Secrets/codes are verified via pragmarx/google2fa
 * (RFC 6238); the QR is rendered elsewhere (App\Services\QrService, already used for passport
 * QR codes) from the provisioning URI this class returns.
 *
 * Recovery codes are individually bcrypt-hashed (same pattern as LoginCode.code_hash) rather
 * than one encrypted blob, so they survive even an APP_KEY compromise.
 */
class TwoFactorService
{
    public const ISSUER = 'DPP Platform';

    public const RECOVERY_CODE_COUNT = 10;

    /** ±1 30-second period of clock-drift tolerance. */
    public const WINDOW = 1;

    /** Failed-attempt lockout, on top of the route-level throttle. */
    public const MAX_ATTEMPTS = 5;

    public const LOCKOUT_SECONDS = 900;

    public function __construct(private Google2FA $google2fa) {}

    /** A fresh secret, not yet persisted (setup isn't confirmed until confirm() succeeds). */
    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    public function provisioningUri(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl(self::ISSUER, $user->email, $secret);
    }

    /** Stateless check against a given secret -- does not touch the database. */
    public function verifyCode(string $secret, string $code): bool
    {
        return (bool) $this->google2fa->verifyKey($secret, $code, self::WINDOW);
    }

    /**
     * Check a submitted code (TOTP or recovery) against $user's own 2FA, applying the shared
     * failed-attempt lockout bookkeeping. Callers still validate request shape (digits:6 etc)
     * and check tooManyAttempts() before calling this.
     */
    public function attemptVerification(User $user, ?string $code, ?string $recoveryCode): bool
    {
        $ok = $recoveryCode
            ? $this->consumeRecoveryCode($user, $recoveryCode)
            : $this->verifyCode($user->two_factor_secret, (string) $code);

        if (! $ok) {
            $this->recordFailedAttempt($user);

            return false;
        }

        $this->clearAttempts($user);

        return true;
    }

    /**
     * Confirm setup: persist the secret + confirmed_at once the user proves control, and issue
     * a fresh batch of recovery codes. Returns the plaintext codes (shown once), or null if the
     * confirmation code was wrong (nothing persisted).
     */
    public function confirm(User $user, string $secret, string $code): ?array
    {
        if (! $this->verifyCode($secret, $code)) {
            return null;
        }

        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();

        return $this->issueRecoveryCodes($user);
    }

    /** Generate a fresh batch (invalidates the old one), returning the plaintext once for display. */
    public function regenerateRecoveryCodes(User $user): array
    {
        return $this->issueRecoveryCodes($user);
    }

    /**
     * Check a submitted recovery code against the stored hashes; if it matches, remove it
     * (single-use) and return true. Locked to prevent the same code being consumed twice by
     * concurrent requests.
     */
    public function consumeRecoveryCode(User $user, string $code): bool
    {
        return DB::transaction(function () use ($user, $code) {
            /** @var User $locked */
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $hashes = $locked->two_factor_recovery_codes ?? [];

            foreach ($hashes as $i => $hash) {
                if (Hash::check($code, $hash)) {
                    unset($hashes[$i]);
                    $locked->forceFill(['two_factor_recovery_codes' => array_values($hashes)])->save();

                    return true;
                }
            }

            return false;
        });
    }

    /** Full reset: clears setup entirely, forcing fresh setup on next login. */
    public function reset(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    public function tooManyAttempts(User $user): bool
    {
        return RateLimiter::tooManyAttempts($this->attemptsKey($user), self::MAX_ATTEMPTS);
    }

    public function availableInSeconds(User $user): int
    {
        return RateLimiter::availableIn($this->attemptsKey($user));
    }

    public function recordFailedAttempt(User $user): void
    {
        RateLimiter::hit($this->attemptsKey($user), self::LOCKOUT_SECONDS);
    }

    public function clearAttempts(User $user): void
    {
        RateLimiter::clear($this->attemptsKey($user));
    }

    private function attemptsKey(User $user): string
    {
        return "2fa-attempts:{$user->id}";
    }

    private function issueRecoveryCodes(User $user): array
    {
        $codes = collect(range(1, self::RECOVERY_CODE_COUNT))
            ->map(fn () => Str::upper(Str::random(4)).'-'.Str::upper(Str::random(4)))
            ->all();

        $user->forceFill([
            'two_factor_recovery_codes' => array_map(fn ($c) => Hash::make($c), $codes),
        ])->save();

        return $codes;
    }
}

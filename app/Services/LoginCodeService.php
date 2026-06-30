<?php

namespace App\Services;

use App\Models\LoginCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Issues and verifies short-lived single-use login codes (passwordless auth).
 *
 * Guardrails: 10-minute expiry, single-use, max 5 verify attempts per code (then burned),
 * code stored only as a bcrypt hash. Request rate limiting is enforced at the route layer.
 */
class LoginCodeService
{
    public const EXPIRY_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const CODE_LENGTH = 6;

    /**
     * Generate, persist (hashed) and return a fresh code for the email.
     * Any earlier unconsumed codes for this email are invalidated first.
     */
    public function issue(string $email): string
    {
        $email = strtolower(trim($email));

        // One live code per email: consume any outstanding ones.
        LoginCode::where('email', $email)->whereNull('consumed_at')
            ->update(['consumed_at' => Carbon::now()]);

        $code = str_pad((string) random_int(0, 999999), self::CODE_LENGTH, '0', STR_PAD_LEFT);

        LoginCode::create([
            'email' => $email,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => Carbon::now()->addMinutes(self::EXPIRY_MINUTES),
        ]);

        return $code;
    }

    /**
     * Verify a submitted code for an email. Returns true on success (and consumes the code).
     * Counts attempts and burns the code once the cap is hit.
     */
    public function verify(string $email, string $code): bool
    {
        $email = strtolower(trim($email));

        $record = LoginCode::where('email', $email)
            ->whereNull('consumed_at')
            ->orderByDesc('created_at')
            ->first();

        if (! $record || $record->isExpired()) {
            return false;
        }

        if ($record->attempts >= self::MAX_ATTEMPTS) {
            $record->update(['consumed_at' => Carbon::now()]);   // burn it

            return false;
        }

        $record->increment('attempts');

        if (! Hash::check($code, $record->code_hash)) {
            return false;
        }

        $record->update(['consumed_at' => Carbon::now()]);

        return true;
    }
}

<?php

namespace App\Services;

use App\Models\Passport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Records a public passport scan into the month-partitioned scan_events table.
 * The scanner IP is stored as a keyed HMAC (GDPR), never raw, so it cannot be reversed.
 */
class ScanLogger
{
    public function log(Passport $passport, Request $request, string $locale): void
    {
        DB::table('scan_events')->insert([
            'passport_id' => $passport->id,
            'ts' => now(),
            'link_type' => null,
            'locale' => $locale,
            'country' => null,
            'ua_class' => $this->uaClass($request->userAgent()),
            'ip_hash' => $this->hashIp($request->ip()),
        ]);
    }

    private function hashIp(?string $ip): ?string
    {
        if (! $ip) {
            return null;
        }

        return hash_hmac('sha256', $ip, (string) config('dpp.scan_ip_hmac_key'));
    }

    private function uaClass(?string $ua): string
    {
        if (! $ua) {
            return 'unknown';
        }

        return str_contains($ua, 'Mobi') ? 'mobile' : 'desktop';
    }
}

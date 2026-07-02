<?php

namespace App\Http\Controllers;

use App\Models\Passport;
use App\Models\PassportAccessToken;
use App\Models\PublishedSnapshot;
use App\Services\ScanLogger;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public passport resolver -- the QR scan target. No auth. Reads ONE pre-built snapshot row
 * (never a live join) and content-negotiates: a browser gets the HTML consumer view, a
 * machine asking for JSON-LD gets the structured data. The OrganizationScope is inert here
 * (no org context is bound on public routes), so any tenant's published passport resolves.
 */
class ResolverController extends Controller
{
    public function __construct(private ScanLogger $scans) {}

    /** Fallback identifier: /p/{public_id} */
    public function showByPublicId(Request $request, string $publicId): Response
    {
        return $this->render($request, Passport::where('public_id', $publicId)->first(), 'consumer');
    }

    /** GS1 Digital Link: /01/{gtin}/21/{serial} (serial optional). */
    public function showByGs1(Request $request, string $gtin, ?string $serial = null): Response
    {
        $query = Passport::where('identifier_scheme', 'gs1')->where('gtin', $gtin);
        if ($serial !== null) {
            $query->where('serial', $serial);
        }

        return $this->render($request, $query->first(), 'consumer');
    }

    /** Tiered access link: /p/{public_id}/{audience}/{token} (repairer/recycler/authority). */
    public function showByTier(Request $request, string $publicId, string $audience, string $token): Response
    {
        $passport = Passport::where('public_id', $publicId)->first();
        abort_if(! $passport, 404);

        // The audience segment is never trusted by itself: a valid token for one audience
        // must not grant access under a different audience's URL slot.
        $valid = PassportAccessToken::where('passport_id', $passport->id)
            ->where('audience', $audience)
            ->where('token', $token)
            ->exists();
        abort_if(! $valid, 404);

        return $this->render($request, $passport, $audience);
    }

    private function render(Request $request, ?Passport $passport, string $audience): Response
    {
        // A published passport must never silently 404 once live, but drafts / unknown ids
        // are genuinely not found to the public.
        abort_if(! $passport || ! $passport->isPublished(), 404);

        // Locales actually built for this passport are the source of truth (not the platform
        // config): a passport published before a locale was added simply doesn't offer it
        // until its snapshots are rebuilt.
        $available = PublishedSnapshot::where('passport_id', $passport->id)
            ->where('audience', $audience)
            ->orderBy('locale')
            ->pluck('locale')
            ->all();

        $locale = $this->chooseLocale($request, $passport, $available);

        $snapshot = PublishedSnapshot::where('passport_id', $passport->id)
            ->where('audience', $audience)
            ->where('locale', $locale)
            ->first();

        abort_if(! $snapshot, 404);

        $this->scans->log($passport, $request, $locale);

        // Content negotiation: machines asking for JSON-LD get structured data.
        if ($this->wantsJsonLd($request)) {
            return response()->json($snapshot->rendered)
                ->header('Content-Type', 'application/ld+json')
                ->header('Cache-Control', 'public, max-age=300')
                ->header('Vary', 'Accept, Accept-Language');
        }

        app()->setLocale($locale); // page chrome (lang/{locale}/public.php)

        return response()
            ->view('public.passport', [
                'p' => $snapshot->rendered,
                'localeUrls' => count($available) > 1
                    ? collect($available)->mapWithKeys(fn ($l) => [$l => $request->fullUrlWithQuery(['lang' => $l])])->all()
                    : [],
                'currentLocale' => $locale,
            ])
            ->header('Cache-Control', 'public, max-age=300')
            ->header('Vary', 'Accept, Accept-Language');
    }

    /**
     * Pick the locale to serve: an explicit ?lang= wins, then Accept-Language (buyer's
     * browser is set to their Member-State language), then the passport's default. Only
     * locales that actually have a snapshot row are ever chosen.
     */
    private function chooseLocale(Request $request, Passport $passport, array $available): string
    {
        $requested = strtolower((string) $request->query('lang'));
        if ($requested !== '' && in_array($requested, $available, true)) {
            return $requested;
        }

        // Only when the header is genuinely present (empty counts as absent): Symfony's
        // getLanguages() otherwise fabricates the framework default ('en'), which must not
        // outrank the passport's own default.
        if (trim((string) $request->headers->get('Accept-Language')) !== '') {
            foreach ($request->getLanguages() as $language) {
                // 'en_GB' / 'en-GB' -> 'en': snapshot locales are primary subtags.
                $primary = strtolower(substr(str_replace('_', '-', $language), 0, 2));
                if (in_array($primary, $available, true)) {
                    return $primary;
                }
            }
        }

        if (in_array($passport->default_locale, $available, true)) {
            return $passport->default_locale;
        }

        return $available[0] ?? $passport->default_locale;
    }

    private function wantsJsonLd(Request $request): bool
    {
        return $request->query('format') === 'json'
            || str_contains((string) $request->header('Accept'), 'application/ld+json')
            || str_contains((string) $request->header('Accept'), 'application/json');
    }
}

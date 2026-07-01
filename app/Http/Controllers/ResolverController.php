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

        $locale = $passport->default_locale;

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
                ->header('Cache-Control', 'public, max-age=300');
        }

        return response()
            ->view('public.passport', ['p' => $snapshot->rendered])
            ->header('Cache-Control', 'public, max-age=300');
    }

    private function wantsJsonLd(Request $request): bool
    {
        return $request->query('format') === 'json'
            || str_contains((string) $request->header('Accept'), 'application/ld+json')
            || str_contains((string) $request->header('Accept'), 'application/json');
    }
}

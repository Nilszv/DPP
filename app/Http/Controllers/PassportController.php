<?php

namespace App\Http\Controllers;

use App\Exceptions\PublishException;
use App\Models\Passport;
use App\Models\Product;
use App\Models\Template;
use App\Services\PassportPublisher;
use App\Services\QrService;
use App\Support\CanonicalJson;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Authenticated passport management. All queries are tenant-scoped automatically
 * (OrganizationScope via org.context middleware), so route-model binding cannot resolve
 * another tenant's passport.
 */
class PassportController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', Passport::class);
        $passports = Passport::with('product')->latest()->get();

        return view('app.passports.index', compact('passports'));
    }

    public function create()
    {
        $this->authorize('create', Passport::class);

        return view('app.passports.create', ['templates' => $this->availableTemplates()]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Passport::class);
        $data = $request->validate([
            'product_name' => ['required', 'string', 'max:255'],
            'template_id' => ['required', 'string'],
        ]);

        $template = $this->availableTemplates()->firstWhere('id', $data['template_id']);
        abort_unless($template, 422, 'Unknown template.');

        $passport = DB::transaction(function () use ($data, $template) {
            $product = Product::create([
                'template_id' => $template->id,
                'name' => $data['product_name'],
                'category' => $template->category,
            ]);

            $passport = Passport::create([
                'product_id' => $product->id,
                'public_id' => (string) Str::uuid(),
                'identifier_scheme' => 'self',
                'status' => 'draft',
                'default_locale' => config('dpp.default_locale'),
            ]);

            $passport->versions()->create([
                'version_no' => 1,
                'data' => [],
                'content_hash' => CanonicalJson::hash([]),
                'created_by' => auth()->id(),
                'locked' => false,
            ]);

            return $passport;
        });

        return redirect()->route('passports.edit', $passport)
            ->with('status', 'Draft created. Fill in the product details.');
    }

    public function edit(Passport $passport)
    {
        $this->authorize('update', $passport);
        $version = $this->workingVersion($passport);
        if ($version->locked) {
            return redirect()->route('passports.show', $passport)
                ->with('error', 'Published passports are locked. Start a correction to change the data.');
        }

        return view('app.passports.edit', [
            'passport' => $passport,
            'template' => $passport->product->template,
            'data' => $version->data ?? [],
            'translations' => $version->translations ?? [],
            'translationLocales' => $this->translationLocales($passport),
            'isCorrection' => $passport->isPublished(),
        ]);
    }

    public function update(Request $request, Passport $passport)
    {
        $this->authorize('update', $passport);
        $version = $this->workingVersion($passport);
        if ($version->locked) {
            return redirect()->route('passports.show', $passport)
                ->with('error', 'Published passports are locked. Start a correction to change the data.');
        }

        $template = $passport->product->template;
        $allowedKeys = collect($template->field_schema)->pluck('key')->all();

        $rules = [];
        foreach ($allowedKeys as $key) {
            $rules['fields.'.$key] = ['nullable', 'string', 'max:5000'];
            foreach ($this->translationLocales($passport) as $locale) {
                $rules["translations.{$locale}.{$key}"] = ['nullable', 'string', 'max:5000'];
            }
        }
        $request->validate($rules);

        // Keep only known template fields (drop anything unexpected).
        $clean = array_intersect_key($request->input('fields', []), array_flip($allowedKeys));

        // Translations: only configured locales x known fields, and only non-blank values --
        // a blank input means "fall back to the original", not "store an empty string".
        $translations = [];
        foreach ($this->translationLocales($passport) as $locale) {
            $values = array_intersect_key($request->input("translations.{$locale}", []), array_flip($allowedKeys));
            $values = array_filter($values, fn ($v) => trim((string) $v) !== '');
            if ($values) {
                $translations[$locale] = $values;
            }
        }

        $version->update([
            'data' => $clean,
            'translations' => $translations ?: null,
            'content_hash' => CanonicalJson::hash($clean),
        ]);

        return redirect()->route('passports.show', $passport)->with('status', 'Saved.');
    }

    public function show(Passport $passport)
    {
        $this->authorize('view', $passport);
        $passport->load('product.template', 'currentVersion', 'accessTokens');

        $tierLinks = $passport->accessTokens->map(fn ($token) => [
            'audience' => $token->audience,
            'url' => $passport->tierUrl($token->audience, $token->token),
        ]);

        return view('app.passports.show', [
            'passport' => $passport,
            'tierLinks' => $tierLinks,
            'canRegenerateTiers' => auth()->user()->can('publish', $passport),
            'canCorrect' => auth()->user()->can('update', $passport),
            'openCorrection' => $passport->openCorrection(),
            'versions' => $passport->versions()->with('creator')->orderByDesc('version_no')->get(),
        ]);
    }

    /**
     * Open a correction draft on a published passport: a new unlocked version seeded from the
     * live one. The public page keeps serving the current version untouched until the
     * correction is published through the same regulated gate.
     */
    public function startCorrection(Passport $passport)
    {
        $this->authorize('update', $passport);
        abort_unless($passport->isPublished(), 404);

        if ($passport->openCorrection()) {
            return redirect()->route('passports.edit', $passport);
        }

        DB::transaction(function () use ($passport) {
            // Same per-org lock as the publisher: a double-click or two tabs would otherwise
            // race to create the same version_no (the unique index would turn one into a 500).
            DB::statement('SELECT pg_advisory_xact_lock(?, hashtext(?))', [1, $passport->organization_id]);

            if ($passport->refresh()->openCorrection()) {
                return;
            }

            $current = $passport->currentVersion;
            $passport->versions()->create([
                'version_no' => (int) $passport->versions()->max('version_no') + 1,
                'data' => $current->data ?? [],
                'translations' => $current->translations,
                'content_hash' => CanonicalJson::hash($current->data ?? []),
                'created_by' => auth()->id(),
                'locked' => false,
            ]);
        });

        return redirect()->route('passports.edit', $passport)
            ->with('status', 'Correction draft created. The public page keeps serving the current version until you publish the correction.');
    }

    public function publishCorrection(Passport $passport, PassportPublisher $publisher)
    {
        $this->authorize('publish', $passport);
        try {
            $publisher->publishCorrection($passport);
        } catch (PublishException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('passports.show', $passport)
            ->with('status', 'Correction published. The public page now serves the corrected data.');
    }

    public function discardCorrection(Passport $passport)
    {
        $this->authorize('update', $passport);
        abort_unless($passport->openCorrection(), 404);

        $discarded = DB::transaction(function () use ($passport) {
            // Same per-org lock as publishCorrection(): without it, a concurrent publish can
            // lock + swap this version live between our check and the delete, and the delete
            // would then remove the version current_version_id points at.
            DB::statement('SELECT pg_advisory_xact_lock(?, hashtext(?))', [1, $passport->organization_id]);

            // Re-read under the lock; null here means a concurrent publish already won.
            $correction = $passport->refresh()->openCorrection();
            $correction?->delete();

            return $correction !== null;
        });

        return $discarded
            ? redirect()->route('passports.show', $passport)
                ->with('status', 'Correction discarded. The published version is unchanged.')
            : redirect()->route('passports.show', $passport)
                ->with('error', 'This correction had already been published, so there was nothing to discard.');
    }

    public function publish(Passport $passport, PassportPublisher $publisher)
    {
        $this->authorize('publish', $passport);
        try {
            $publisher->publish($passport);
        } catch (PublishException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('passports.show', $passport)
            ->with('status', 'Published. Your passport is now live and scannable.');
    }

    public function regenerateTier(Passport $passport, string $audience)
    {
        $this->authorize('publish', $passport);
        abort_unless($passport->isPublished(), 404);

        $passport->accessTokens()->where('audience', $audience)->firstOrFail()->regenerate();

        return redirect()->route('passports.show', $passport)
            ->with('status', ucfirst($audience).' link regenerated. The old link no longer works.');
    }

    public function qr(Passport $passport, QrService $qr)
    {
        $this->authorize('view', $passport);

        return response($qr->svg($passport->resolverUrl()), 200, [
            'Content-Type' => 'image/svg+xml',
        ]);
    }

    /** Global templates plus any owned by the current organization. */
    private function availableTemplates()
    {
        return Template::where('active', true)
            ->where(function ($q) {
                $q->whereNull('organization_id')
                    ->orWhere('organization_id', app('currentOrganizationId'));
            })
            ->orderBy('name')
            ->get();
    }

    /** The latest unlocked version being edited (drafts have exactly one). */
    private function workingVersion(Passport $passport)
    {
        return $passport->versions()->orderByDesc('version_no')->first();
    }

    /**
     * Public locales the manufacturer can translate values INTO: everything configured except
     * the passport's own default (that language is what the base fields already hold).
     */
    private function translationLocales(Passport $passport): array
    {
        return array_values(array_diff(config('dpp.locales'), [$passport->default_locale]));
    }
}

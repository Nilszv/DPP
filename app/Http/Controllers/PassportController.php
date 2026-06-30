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
        if ($passport->isPublished()) {
            return redirect()->route('passports.show', $passport)
                ->with('error', 'Published passports are locked. Versioned editing comes later.');
        }

        $template = $passport->product->template;
        $version = $this->workingVersion($passport);

        return view('app.passports.edit', [
            'passport' => $passport,
            'template' => $template,
            'data' => $version->data ?? [],
        ]);
    }

    public function update(Request $request, Passport $passport)
    {
        $this->authorize('update', $passport);
        if ($passport->isPublished()) {
            return redirect()->route('passports.show', $passport)
                ->with('error', 'Published passports are locked.');
        }

        $template = $passport->product->template;
        $allowedKeys = collect($template->field_schema)->pluck('key')->all();

        $rules = [];
        foreach ($allowedKeys as $key) {
            $rules['fields.'.$key] = ['nullable', 'string', 'max:5000'];
        }
        $request->validate($rules);

        // Keep only known template fields (drop anything unexpected).
        $clean = array_intersect_key($request->input('fields', []), array_flip($allowedKeys));

        $version = $this->workingVersion($passport);
        $version->update(['data' => $clean, 'content_hash' => CanonicalJson::hash($clean)]);

        return redirect()->route('passports.show', $passport)->with('status', 'Saved.');
    }

    public function show(Passport $passport)
    {
        $this->authorize('view', $passport);
        $passport->load('product.template', 'currentVersion');

        return view('app.passports.show', ['passport' => $passport]);
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
}

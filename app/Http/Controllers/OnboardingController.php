<?php

namespace App\Http\Controllers;

use App\Mail\DuplicateRegistrationAlert;
use App\Models\LegalAcceptance;
use App\Models\LegalDocument;
use App\Models\Organization;
use App\Models\User;
use App\Support\VatNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * First-run onboarding: collect the company profile (incl. country for tax) and require
 * explicit acceptance of every legal document before the org can use the app.
 */
class OnboardingController extends Controller
{
    public function show(Request $request)
    {
        $org = $this->currentOrg();

        if ($org->isOnboarded()) {
            return redirect()->route('dashboard');
        }

        return view('app.onboarding', [
            'org' => $org,
            'countries' => config('tax.countries'),
            'documents' => LegalDocument::requiredForAcceptance(),
        ]);
    }

    public function store(Request $request)
    {
        $org = $this->currentOrg();

        // Onboarding is a one-time flow. Once complete, profile edits must go through the
        // owner/admin-gated profile page -- not a re-POST here (which any member could do).
        if ($org->isOnboarded()) {
            return redirect()->route('dashboard');
        }

        $documents = LegalDocument::requiredForAcceptance();

        // Never let onboarding complete without any policy acceptance. If the catalogue is
        // empty (e.g. a deploy that migrated but did not seed), this is a misconfiguration.
        abort_if($documents->isEmpty(), 503, 'Registration is temporarily unavailable. Please try again shortly.');

        // VAT is required only for countries that actually operate a VAT number (i.e. have a
        // prefix configured); countries without one (e.g. US) may leave it blank.
        $countriesWithoutVat = array_keys(array_filter(
            config('tax.countries'),
            fn ($c) => empty($c['vat_prefix'])
        ));

        // Company profile rules (easy to adjust: edit this list). Everything is required
        // except the optional second address line.
        $rules = [
            'legal_name' => ['required', 'string', 'max:255'],
            'registration_number' => ['required', 'string', 'max:100'],
            'vat_id' => ['nullable', Rule::requiredIf(fn () => ! in_array($request->input('country'), $countriesWithoutVat, true)), 'string', 'max:50', $this->vatFormatRule($request)],
            'address_line1' => ['required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:120'],
            'postal_code' => ['required', 'string', 'max:20'],
            'country' => ['required', Rule::in(array_keys(config('tax.countries')))],
            'contact_name' => ['required', 'string', 'max:255'],
            'contact_email' => ['required', 'email', 'max:255'],
            'contact_phone' => ['required', 'string', 'max:50'],
        ];

        // Every required legal document must be explicitly accepted.
        foreach ($documents as $doc) {
            $rules["accept.{$doc->key}"] = ['accepted'];
        }

        $data = $request->validate($rules, [
            'accept.*.accepted' => 'You must read and accept all policies to complete registration.',
        ]);

        // Canonicalize VAT server-side so storage and the duplicate check both use one form
        // (a hand-crafted POST cannot slip a formatting variant past the guard).
        $data['vat_id'] = VatNumber::canonical($data['country'], $data['vat_id'] ?? null);

        // Duplicate-account guard: three independent checks, any one of which flags a possible
        // duplicate -- (1) company name + country, (2) registration number + country, (3) VAT
        // number alone. Repeated attempts suspend the email account (anti-abuse: stops
        // re-registering to farm free plans).
        if ($duplicate = $this->findDuplicateOrganization($org, $data)) {
            return $this->handleDuplicate($request, $duplicate);
        }

        DB::transaction(function () use ($org, $request, $data, $documents) {
            $org->update([
                'name' => $data['legal_name'],            // display name = company name
                'legal_name' => $data['legal_name'],
                'registration_number' => $data['registration_number'] ?? null,
                'vat_id' => $data['vat_id'] ?? null,
                'address_line1' => $data['address_line1'],
                'address_line2' => $data['address_line2'] ?? null,
                'city' => $data['city'],
                'postal_code' => $data['postal_code'],
                'country' => $data['country'],
                'contact_name' => $data['contact_name'],
                'contact_email' => $data['contact_email'],
                'contact_phone' => $data['contact_phone'] ?? null,
                'onboarding_completed_at' => now(),
            ]);

            foreach ($documents as $doc) {
                LegalAcceptance::create([
                    'organization_id' => $org->id,
                    'user_id' => $request->user()->id,
                    'document_key' => $doc->key,
                    'document_version' => $doc->version,
                    'ip_hash' => $request->ip()
                        ? hash_hmac('sha256', $request->ip(), (string) config('dpp.scan_ip_hmac_key'))
                        : null,
                    'accepted_at' => now(),
                ]);
            }
        });

        return redirect()->route('dashboard')
            ->with('status', 'Registration complete. Welcome aboard.');
    }

    /**
     * A validation rule that rejects a VAT number whose format is wrong for the selected
     * country (server-side, so it does not depend on the browser). Empty / VAT-less countries
     * pass; requiredness is enforced separately.
     */
    private function vatFormatRule(Request $request): callable
    {
        return function (string $attribute, $value, $fail) use ($request) {
            if (! VatNumber::isValid($request->input('country'), is_string($value) ? $value : null)) {
                $fail('Enter a valid VAT number for the selected country.');
            }
        };
    }

    /**
     * Find an already-registered organization that duplicates this one. Three independent
     * guardrails, checked in order -- any single hit flags a possible duplicate:
     *   1. Company name + country (case/whitespace-insensitive; a name is unique per country).
     *   2. Registration number + country (formatting-insensitive on the number). Registration
     *      numbers are issued by national registries, so the same digits can coincidentally
     *      exist in two different countries -- that alone isn't evidence of duplication, so this
     *      check is scoped to the country like the name check, but stays independent of name.
     *   3. VAT number alone (already canonical -- includes the country prefix, so it is already
     *      effectively country-scoped), regardless of name/registration number.
     * Each check is independent on purpose: the same company re-registering with a differently
     * spelled name, or a typo'd/reformatted registration or VAT number, must still be caught by
     * whichever field it kept consistent. Only completed registrations count as existing.
     *
     * @return array{organization: Organization, field: string, label: string}|null
     */
    private function findDuplicateOrganization(Organization $org, array $data): ?array
    {
        $legal = preg_replace('/\s+/', ' ', mb_strtolower(trim((string) ($data['legal_name'] ?? ''))));
        $reg = preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) ($data['registration_number'] ?? '')));
        $country = $data['country'] ?? null;
        // $data['vat_id'] is already canonical (upper, alnum, country-prefixed) by the caller.
        $vat = $data['vat_id'] ?? null;

        $nameCol = "regexp_replace(lower(btrim(coalesce(legal_name, ''))), '\\s+', ' ', 'g')";
        $regCol = "regexp_replace(lower(coalesce(registration_number, '')), '[^a-z0-9]', '', 'g')";
        $vatCol = "upper(regexp_replace(coalesce(vat_id, ''), '[^A-Za-z0-9]', '', 'g'))";

        $existing = fn () => Organization::query()
            ->whereKeyNot($org->id)
            ->whereNotNull('onboarding_completed_at');

        if ($legal !== '' && $country !== null) {
            if ($match = $existing()->whereRaw($nameCol.' = ?', [$legal])->where('country', $country)->first()) {
                return ['organization' => $match, 'field' => 'legal_name', 'label' => 'company name'];
            }
        }

        if ($reg !== '' && $country !== null) {
            if ($match = $existing()->whereRaw($regCol.' = ?', [$reg])->where('country', $country)->first()) {
                return ['organization' => $match, 'field' => 'registration_number', 'label' => 'registration number'];
            }
        }

        if ($vat !== null) {
            if ($match = $existing()->whereRaw($vatCol.' = ?', [$vat])->first()) {
                return ['organization' => $match, 'field' => 'vat_id', 'label' => 'VAT number'];
            }
        }

        return null;
    }

    /**
     * Record a blocked duplicate attempt. Below the threshold, return the user to the form
     * with an error on whichever field triggered the match. Once attempts exceed the threshold,
     * suspend the email account, alert support (admin-only reason), and send the user to the
     * support page.
     *
     * @param  array{organization: Organization, field: string, label: string}  $duplicate
     */
    private function handleDuplicate(Request $request, array $duplicate)
    {
        ['organization' => $match, 'field' => $field, 'label' => $label] = $duplicate;
        $threshold = (int) config('dpp.onboarding_duplicate_max_attempts', 3);

        $suspended = DB::transaction(function () use ($request, $match, $label, $threshold): ?User {
            /** @var User $user */
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $user->duplicate_onboarding_attempts++;

            $reason = sprintf(
                'Duplicate registration blocked %d time(s). Matched on %s against existing '
                .'organization "%s" (id %s).',
                $user->duplicate_onboarding_attempts,
                $label,
                $match->name,
                $match->id,
            );

            if ($user->duplicate_onboarding_attempts > $threshold) {
                $user->suspended_at = now();
                $user->suspension_reason = $reason;
            }

            $user->save();

            return $user->isSuspended() ? $user : null;
        });

        if ($suspended) {
            Mail::to(config('dpp.support_email'))->send(new DuplicateRegistrationAlert(
                user: $suspended,
                matchedOrganization: $match,
                reason: (string) $suspended->suspension_reason,
                attempts: (int) $suspended->duplicate_onboarding_attempts,
            ));

            return redirect()->route('support.show');
        }

        // duplicate_notice drives a dedicated, prominent explanation banner on the onboarding
        // page (see resources/views/app/onboarding.blade.php) -- a generic "check the
        // highlighted fields" message isn't enough context for what a duplicate match means or
        // what to do about it.
        return back()->withInput()->with('duplicate_notice', true)->withErrors([
            $field => "An organization with this {$label} is already registered.",
        ]);
    }

    private function currentOrg(): Organization
    {
        return Organization::findOrFail(app('currentOrganizationId'));
    }
}

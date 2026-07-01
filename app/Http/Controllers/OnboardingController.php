<?php

namespace App\Http\Controllers;

use App\Mail\DuplicateRegistrationAlert;
use App\Models\LegalAcceptance;
use App\Models\LegalDocument;
use App\Models\Organization;
use App\Models\User;
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
            'vat_id' => ['nullable', Rule::requiredIf(fn () => ! in_array($request->input('country'), $countriesWithoutVat, true)), 'string', 'max:50'],
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

        // Duplicate-account guard: if the company name, registration number AND VAT number
        // all match an already-registered organization, block completion. Repeated attempts
        // suspend the email account (anti-abuse: stops re-registering to farm free plans).
        if ($match = $this->findDuplicateOrganization($org, $data)) {
            return $this->handleDuplicate($request, $match);
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
     * Find an already-registered organization whose company name, registration number AND
     * VAT number all match the submitted values (case-insensitive, whitespace-normalized).
     * Only completed registrations count. Returns null when no full match exists.
     */
    private function findDuplicateOrganization(Organization $org, array $data): ?Organization
    {
        $norm = fn (?string $v): string => preg_replace('/\s+/', ' ', mb_strtolower(trim((string) $v)));

        $legal = $norm($data['legal_name'] ?? '');
        $reg = $norm($data['registration_number'] ?? '');
        $vat = $norm($data['vat_id'] ?? '');

        // All three must be present to assert a duplicate (e.g. a VAT-less country cannot).
        if ($legal === '' || $reg === '' || $vat === '') {
            return null;
        }

        // Normalize the stored column the same way (lower + trim + collapse whitespace).
        $col = fn (string $c): string => "regexp_replace(lower(btrim(coalesce($c, ''))), '\\s+', ' ', 'g')";

        return Organization::query()
            ->whereKeyNot($org->id)
            ->whereNotNull('onboarding_completed_at')
            ->whereRaw($col('legal_name').' = ?', [$legal])
            ->whereRaw($col('registration_number').' = ?', [$reg])
            ->whereRaw($col('vat_id').' = ?', [$vat])
            ->first();
    }

    /**
     * Record a blocked duplicate attempt. Below the threshold, return the user to the form
     * with an error. Once attempts exceed the threshold, suspend the email account, alert
     * support (admin-only reason), and send the user to the support page.
     */
    private function handleDuplicate(Request $request, Organization $match)
    {
        $threshold = (int) config('dpp.onboarding_duplicate_max_attempts', 3);

        $suspended = DB::transaction(function () use ($request, $match, $threshold): ?User {
            /** @var User $user */
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $user->duplicate_onboarding_attempts++;

            $reason = sprintf(
                'Duplicate registration blocked %d time(s). Company name, registration number and '
                .'VAT number all match existing organization "%s" (id %s).',
                $user->duplicate_onboarding_attempts,
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

        return back()->withInput()->withErrors([
            'vat_id' => 'An organization with this company name, registration number and VAT '
                .'number is already registered. If this is your company, please contact support.',
        ]);
    }

    private function currentOrg(): Organization
    {
        return Organization::findOrFail(app('currentOrganizationId'));
    }
}

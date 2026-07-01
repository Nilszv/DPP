<?php

namespace App\Http\Controllers;

use App\Models\LegalAcceptance;
use App\Models\LegalDocument;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        // Company profile rules (easy to adjust: edit this list).
        $rules = [
            'legal_name' => ['required', 'string', 'max:255'],
            'registration_number' => ['nullable', 'string', 'max:100'],
            'vat_id' => ['required', 'string', 'max:50'],
            'address_line1' => ['required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:120'],
            'postal_code' => ['required', 'string', 'max:20'],
            'country' => ['required', Rule::in(array_keys(config('tax.countries')))],
            'contact_name' => ['required', 'string', 'max:255'],
            'contact_email' => ['required', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
        ];

        // Every required legal document must be explicitly accepted.
        foreach ($documents as $doc) {
            $rules["accept.{$doc->key}"] = ['accepted'];
        }

        $data = $request->validate($rules, [
            'accept.*.accepted' => 'You must read and accept all policies to complete registration.',
        ]);

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

    private function currentOrg(): Organization
    {
        return Organization::findOrFail(app('currentOrganizationId'));
    }
}

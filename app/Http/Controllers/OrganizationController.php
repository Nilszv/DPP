<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The organization profile: company data captured at onboarding, editable by owner/admin. */
class OrganizationController extends Controller
{
    public function show()
    {
        return view('app.organization', [
            'org' => $this->currentOrg(),
            'countries' => config('tax.countries'),
            'canManage' => auth()->user()->canManageOrg(),
        ]);
    }

    public function update(Request $request)
    {
        abort_unless(auth()->user()->canManageOrg(), 403);

        $data = $request->validate([
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
        ]);

        $data['name'] = $data['legal_name'];   // display name mirrors the company name
        $this->currentOrg()->update($data);

        return redirect()->route('organization.show')->with('status', 'Company profile updated.');
    }

    private function currentOrg(): Organization
    {
        return Organization::findOrFail(app('currentOrganizationId'));
    }
}

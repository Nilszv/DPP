@extends('layouts.app')
@section('title', 'Company profile - DPP Platform')

@section('content')
    <h1>Company profile</h1>

    <section>
        <dl>
            <dt>Company name</dt><dd>{{ $org->legal_name ?? $org->name }}</dd>
            <dt>Registration no.</dt><dd>{{ $org->registration_number ?: '-' }}</dd>
            <dt>VAT number</dt><dd>{{ $org->vat_id ?: '-' }}</dd>
            <dt>Address</dt>
            <dd>
                {{ $org->address_line1 }}@if ($org->address_line2), {{ $org->address_line2 }}@endif<br>
                {{ $org->postal_code }} {{ $org->city }}<br>
                {{ $org->countryName() ?? $org->country }}
            </dd>
            <dt>Applicable VAT</dt>
            <dd>{{ $org->taxRate() !== null ? rtrim(rtrim(number_format($org->taxRate(), 1), '0'), '.').'%' : '-' }}</dd>
            <dt>Contact</dt>
            <dd>{{ $org->contact_name }} &lt;{{ $org->contact_email }}&gt;@if ($org->contact_phone), {{ $org->contact_phone }}@endif</dd>
        </dl>
    </section>

    @if ($canManage)
        <section>
            <h2>Edit company profile</h2>
            <form method="POST" action="{{ route('organization.update') }}">
                @csrf
                @method('PUT')
                @include('app.partials.company-fields', ['org' => $org, 'countries' => $countries])
                <div class="form-actions">
                    <button type="submit">Save</button>
                </div>
            </form>
        </section>
    @else
        <p class="muted">Only an owner or admin can edit the company profile.</p>
    @endif
@endsection

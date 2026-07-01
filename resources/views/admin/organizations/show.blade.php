@extends('layouts.admin')
@section('title', $organization->name.' - DPP Admin')

@section('content')
    <p><a href="{{ route('admin.organizations') }}">&larr; Organizations</a></p>
    <h1>{{ $organization->legal_name ?? $organization->name }}</h1>
    <p>
        <a class="button" href="{{ route('admin.organizations.edit', $organization) }}">Edit plan / quota / status</a>
        <a href="{{ route('admin.passports.index', ['org' => $organization->id]) }}">View QR codes</a>
    </p>

    <section>
        <h2>Company profile</h2>
        <dl>
            <dt>Company name</dt><dd>{{ $organization->legal_name ?: $organization->name }}</dd>
            <dt>Registration no.</dt><dd>{{ $organization->registration_number ?: '-' }}</dd>
            <dt>VAT number</dt><dd>{{ $organization->vat_id ?: '-' }}</dd>
            <dt>Address</dt>
            <dd>
                {{ $organization->address_line1 ?: '-' }}@if ($organization->address_line2), {{ $organization->address_line2 }}@endif<br>
                {{ $organization->postal_code }} {{ $organization->city }}<br>
                {{ $organization->countryName() ?? ($organization->country ?: '-') }}
            </dd>
            <dt>Applicable VAT</dt>
            <dd>{{ $organization->taxRate() !== null ? rtrim(rtrim(number_format($organization->taxRate(), 1), '0'), '.').'%' : '-' }}</dd>
            <dt>Contact</dt>
            <dd>{{ $organization->contact_name ?: '-' }} &lt;{{ $organization->contact_email }}&gt;@if ($organization->contact_phone), {{ $organization->contact_phone }}@endif</dd>
            <dt>Onboarded</dt><dd>{{ $organization->onboarding_completed_at?->toDayDateTimeString() ?? 'not yet' }}</dd>
        </dl>
    </section>

    <section>
        <h2>Billing</h2>
        <dl>
            <dt>Plan</dt><dd>{{ $organization->planName() }} ({{ $organization->status }})</dd>
            <dt>Published quota</dt>
            <dd>{{ $organization->publishedQuota() === PHP_INT_MAX ? 'Unlimited' : $organization->publishedQuota() }}@if ($organization->published_quota_override !== null) <span class="muted">(override)</span>@endif</dd>
            <dt>Effective price</dt>
            <dd>{{ $organization->effectivePrice() === null ? 'custom/contact' : config('billing.currency').' '.$organization->effectivePrice() }}@if ($organization->effectiveInterval()) / {{ $organization->effectiveInterval() }}@endif</dd>
            <dt>Passports</dt><dd>{{ $publishedCount }} published, {{ $draftCount }} draft</dd>
        </dl>
    </section>

    <section>
        <h2>Members</h2>
        <table>
            <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th></th></tr></thead>
            <tbody>
                @foreach ($organization->members as $member)
                    <tr>
                        <td>{{ $member->name }}</td>
                        <td>{{ $member->email }}</td>
                        <td>{{ $member->pivot->role }}</td>
                        <td>
                            @if ($member->isSuspended())
                                <span class="field-error" title="{{ $member->suspension_reason }}">Suspended</span>
                            @else
                                Active
                            @endif
                        </td>
                        <td>
                            @if ($member->isSuspended())
                                <form method="POST" action="{{ route('admin.users.unsuspend', $member) }}">
                                    @csrf
                                    <button type="submit" class="button-secondary">Lift suspension</button>
                                </form>
                            @endif
                            @unless ($member->is(auth()->user()) || $member->isAdmin())
                                <form method="POST" action="{{ route('admin.impersonate.start', $member) }}">
                                    @csrf
                                    <button type="submit" class="button-secondary">Impersonate</button>
                                </form>
                            @endunless
                            @unless ($member->is(auth()->user()))
                                <form method="POST" action="{{ route('admin.users.delete', $member) }}"
                                      onsubmit="return confirm('Delete {{ $member->email }}? This also deletes any organization where they are the sole member, and cannot be undone.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="button-secondary">Delete user</button>
                                </form>
                            @endunless
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if ($organization->members->contains(fn ($m) => $m->isSuspended()))
            <p class="muted">A suspended member's reason (admin only) is shown on hover of the Suspended label.</p>
        @endif
    </section>

    <section>
        <h2>Legal acceptances</h2>
        @if ($acceptances->isEmpty())
            <p class="muted">None recorded.</p>
        @else
            <table>
                <thead><tr><th>Document</th><th>Version</th><th>Accepted by</th><th>When</th></tr></thead>
                <tbody>
                    @foreach ($acceptances as $a)
                        <tr>
                            <td><code>{{ $a->document_key }}</code></td>
                            <td>{{ $a->document_version }}</td>
                            <td>{{ $a->user?->email ?? '-' }}</td>
                            <td>{{ $a->accepted_at?->toDayDateTimeString() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
@endsection

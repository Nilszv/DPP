@extends('layouts.app')
@section('title', 'Plan & billing - DPP Platform')

@section('content')
    <h1>Plan & billing</h1>

    @if ($isManual)
        <p class="flash-status">
            Billing is in <strong>manual mode</strong>: plan changes take effect immediately and
            no payment is taken yet. Card payments turn on when Stripe is connected.
        </p>
    @endif

    <section>
        <h2>Current plan</h2>
        <dl>
            <dt>Plan</dt><dd>{{ $org->planName() }}</dd>
            <dt>Published passports</dt>
            <dd>{{ $published }} / {{ $org->publishedQuota() === PHP_INT_MAX ? 'Unlimited' : $org->publishedQuota() }}</dd>
        </dl>
    </section>

    <section>
        <h2>Plans</h2>
        @unless ($canManage)
            <p class="muted">Only an owner or admin can change the plan.</p>
        @endunless

        <table>
            <thead>
                <tr><th>Plan</th><th>Price</th><th>Published quota</th><th></th></tr>
            </thead>
            <tbody>
                @foreach ($plans as $key => $plan)
                    <tr>
                        <td>{{ $plan['name'] }}</td>
                        <td>
                            @if ($plan['price'] === null)
                                Custom
                            @elseif ($plan['price'] == 0)
                                Free
                            @else
                                {{ $currency }} {{ $plan['price'] }}@if ($plan['interval']) / {{ $plan['interval'] }}@endif
                            @endif
                        </td>
                        <td>{{ $plan['published_quota'] === PHP_INT_MAX ? 'Unlimited' : $plan['published_quota'] }}</td>
                        <td>
                            @if ($key === $org->plan)
                                <span class="muted">Current</span>
                            @elseif ($key === 'commercial')
                                <span class="muted">Contact sales</span>
                            @elseif ($canManage)
                                <form method="POST" action="{{ route('billing.switch') }}">
                                    @csrf
                                    <input type="hidden" name="plan" value="{{ $key }}">
                                    <button type="submit">Switch to {{ $plan['name'] }}</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
@endsection

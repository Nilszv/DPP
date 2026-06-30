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
                @foreach ($plans as $plan)
                    <tr>
                        <td>{{ $plan->name }}</td>
                        <td>
                            @if ($plan->price === null)
                                Custom
                            @elseif ($plan->price == 0)
                                Free
                            @else
                                {{ $currency }} {{ $plan->price }}@if ($plan->interval) / {{ $plan->interval }}@endif
                            @endif
                        </td>
                        <td>{{ $plan->published_quota === null ? 'Unlimited' : $plan->published_quota }}</td>
                        <td>
                            @php($fits = $published <= ($plan->published_quota ?? PHP_INT_MAX))
                            @if ($plan->key === $org->plan)
                                <span class="muted">Current</span>
                            @elseif ($plan->price === null || ! $fits)
                                {{-- Custom plan, or a downgrade that would strand published passports. --}}
                                @if ($canManage)
                                    <button type="button" onclick="openContactSales('{{ $plan->name }}')">Contact sales</button>
                                    @unless ($fits)
                                        <div class="muted">Too many published passports to downgrade.</div>
                                    @endunless
                                @else
                                    <span class="muted">Contact sales</span>
                                @endif
                            @elseif ($canManage)
                                <form method="POST" action="{{ route('billing.switch') }}">
                                    @csrf
                                    <input type="hidden" name="plan" value="{{ $plan->key }}">
                                    <button type="submit">Switch to {{ $plan->name }}</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    {{-- Contact sales modal (native dialog; minimal JS to open/close). --}}
    <dialog id="contactSales">
        <form method="POST" action="{{ route('contact.sales') }}">
            @csrf
            <input type="hidden" name="interest" id="contactInterest" value="">
            <h2>Contact sales</h2>
            <p class="muted">
                Tell us what you need (for example a downgrade, or a custom plan) and we will get back to you.
                Published passports must stay hosted for 10+ years, so downgrades are arranged with us.
            </p>
            <div class="form-row">
                <label for="contactMessage">Message</label>
                <textarea id="contactMessage" name="message" rows="5" required></textarea>
            </div>
            <div class="form-actions">
                <button type="submit">Send</button>
                <button type="button" onclick="document.getElementById('contactSales').close()">Cancel</button>
            </div>
        </form>
    </dialog>

    <script>
        function openContactSales(interest) {
            var field = document.getElementById('contactInterest');
            if (field) { field.value = interest || ''; }
            document.getElementById('contactSales').showModal();
        }
    </script>
@endsection

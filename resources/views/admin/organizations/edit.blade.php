@extends('layouts.admin')
@section('title', 'Edit organization - DPP Admin')

@section('content')
    <p><a href="{{ route('admin.organizations') }}">&larr; Organizations</a></p>
    <h1>{{ $organization->name }}</h1>
    <p class="muted">Currently {{ $publishedCount }} published passport(s).</p>

    <form method="POST" action="{{ route('admin.organizations.update', $organization) }}">
        @csrf
        @method('PUT')

        <div class="form-row">
            <label for="plan">Plan</label>
            <select id="plan" name="plan">
                @foreach ($plans as $plan)
                    <option value="{{ $plan->key }}" @selected($organization->plan === $plan->key)>
                        {{ $plan->name }} ({{ $plan->published_quota === null ? 'unlimited' : $plan->published_quota }})
                    </option>
                @endforeach
            </select>
            @error('plan')<p class="field-error">{{ $message }}</p>@enderror
        </div>

        <div class="form-row">
            <label for="published_quota_override">Published-quota override</label>
            <input id="published_quota_override" name="published_quota_override" type="number" min="0"
                   value="{{ old('published_quota_override', $organization->published_quota_override) }}">
            <span class="muted">Leave empty to use the plan's quota. Set a number for a custom deal.</span>
            @error('published_quota_override')<p class="field-error">{{ $message }}</p>@enderror
        </div>

        <div class="form-row">
            <label for="price_override">Price override ({{ config('billing.currency') }})</label>
            <input id="price_override" name="price_override" type="number" step="0.01" min="0"
                   value="{{ old('price_override', $organization->price_override) }}">
            <span class="muted">Custom price for this org (e.g. a negotiated commercial rate). Empty = use the plan's price.</span>
            @error('price_override')<p class="field-error">{{ $message }}</p>@enderror
        </div>

        <div class="form-row">
            <label for="interval_override">Billing interval override</label>
            <select id="interval_override" name="interval_override">
                @foreach (['' => 'use plan', 'month' => 'monthly', 'year' => 'yearly'] as $val => $label)
                    <option value="{{ $val }}" @selected(old('interval_override', $organization->interval_override) === ($val ?: null))>{{ $label }}</option>
                @endforeach
            </select>
            @error('interval_override')<p class="field-error">{{ $message }}</p>@enderror
        </div>

        <p class="muted">
            Effective billing: {{ $organization->effectivePrice() === null ? 'custom/contact' : config('billing.currency').' '.$organization->effectivePrice() }}@if ($organization->effectiveInterval()) / {{ $organization->effectiveInterval() }}@endif
        </p>

        <div class="form-row">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="active" @selected($organization->status === 'active')>active</option>
                <option value="suspended" @selected($organization->status === 'suspended')>suspended</option>
            </select>
        </div>

        <div class="form-actions">
            <button type="submit">Save</button>
        </div>
    </form>
@endsection

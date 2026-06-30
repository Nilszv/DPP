@extends('layouts.admin')
@section('title', ($plan->exists ? 'Edit' : 'New').' plan - DPP Admin')

@section('content')
    <p><a href="{{ route('admin.plans.index') }}">&larr; Plans</a></p>
    <h1>{{ $plan->exists ? 'Edit plan: '.$plan->name : 'New plan' }}</h1>

    <form method="POST" action="{{ $plan->exists ? route('admin.plans.update', $plan) : route('admin.plans.store') }}">
        @csrf
        @if ($plan->exists) @method('PUT') @endif

        <div class="form-row">
            <label for="key">Key (lowercase, no spaces)</label>
            <input id="key" name="key" type="text" value="{{ old('key', $plan->key) }}" @if ($plan->exists) readonly @endif>
            @error('key')<p class="field-error">{{ $message }}</p>@enderror
        </div>

        <div class="form-row">
            <label for="name">Name</label>
            <input id="name" name="name" type="text" value="{{ old('name', $plan->name) }}">
            @error('name')<p class="field-error">{{ $message }}</p>@enderror
        </div>

        <div class="form-row">
            <label for="price">Price (leave empty for custom/contact)</label>
            <input id="price" name="price" type="number" step="0.01" min="0" value="{{ old('price', $plan->price) }}">
            @error('price')<p class="field-error">{{ $message }}</p>@enderror
        </div>

        <div class="form-row">
            <label for="interval">Interval</label>
            <select id="interval" name="interval">
                @foreach (['' => '-', 'month' => 'month', 'year' => 'year', 'custom' => 'custom'] as $val => $label)
                    <option value="{{ $val }}" @selected(old('interval', $plan->interval) === ($val ?: null))>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="form-row">
            <label for="published_quota">Published quota (leave empty for unlimited)</label>
            <input id="published_quota" name="published_quota" type="number" min="0" value="{{ old('published_quota', $plan->published_quota) }}">
            @error('published_quota')<p class="field-error">{{ $message }}</p>@enderror
        </div>

        <div class="form-row">
            <label for="sort">Sort order</label>
            <input id="sort" name="sort" type="number" value="{{ old('sort', $plan->sort ?? 0) }}">
        </div>

        <div class="form-row form-row-checkbox">
            <label><input type="checkbox" name="is_public" value="1" @checked(old('is_public', $plan->is_public))> Public (shown on the tenant plan page)</label>
        </div>
        <div class="form-row form-row-checkbox">
            <label><input type="checkbox" name="active" value="1" @checked(old('active', $plan->active ?? true))> Active</label>
        </div>

        <div class="form-actions">
            <button type="submit">Save</button>
        </div>
    </form>
@endsection

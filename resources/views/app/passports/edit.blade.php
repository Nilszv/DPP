@extends('layouts.app')
@section('title', 'Edit passport - DPP Platform')

@section('content')
    <h1>Edit: {{ $passport->product->name }}</h1>
    <p class="muted">Fields marked required must be completed before you can publish.</p>

    <form method="POST" action="{{ route('passports.update', $passport) }}">
        @csrf
        @method('PUT')

        @foreach ($template->field_schema as $field)
            <div class="form-row">
                <label for="field_{{ $field['key'] }}">
                    {{ $field['label'] }}@if ($field['required'] ?? false) <span class="muted">(required)</span>@endif
                </label>

                @if (($field['type'] ?? 'text') === 'textarea')
                    <textarea id="field_{{ $field['key'] }}" name="fields[{{ $field['key'] }}]" rows="3">{{ old('fields.'.$field['key'], $data[$field['key']] ?? '') }}</textarea>
                @else
                    <input id="field_{{ $field['key'] }}" type="text" name="fields[{{ $field['key'] }}]" value="{{ old('fields.'.$field['key'], $data[$field['key']] ?? '') }}">
                @endif
            </div>
        @endforeach

        <div class="form-actions">
            <button type="submit">Save</button>
            <a href="{{ route('passports.show', $passport) }}">Cancel</a>
        </div>
    </form>
@endsection

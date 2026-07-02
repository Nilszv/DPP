@extends('layouts.app')
@section('title', 'Edit passport - DPP Platform')

@section('content')
    <h1>Edit: {{ $passport->product->name }}</h1>
    @if ($isCorrection ?? false)
        <p><strong>You are editing a correction to a published passport.</strong>
            The public page keeps serving the current version until you publish this correction from the passport page.</p>
    @else
        <p class="muted">Fields marked required must be completed before you can publish.</p>
    @endif

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

        @foreach ($translationLocales as $locale)
            <h2>Translations &mdash; {{ strtoupper($locale) }}</h2>
            <p class="muted">
                Optional. A blank field serves the original value on the {{ strtoupper($locale) }} public page.
                Translations are your responsibility &mdash; this platform never machine-translates product data.
            </p>

            @foreach ($template->field_schema as $field)
                <div class="form-row">
                    <label for="tr_{{ $locale }}_{{ $field['key'] }}">{{ $field['label'] }} ({{ strtoupper($locale) }})</label>

                    @if (($field['type'] ?? 'text') === 'textarea')
                        <textarea id="tr_{{ $locale }}_{{ $field['key'] }}" name="translations[{{ $locale }}][{{ $field['key'] }}]" rows="3"
                            placeholder="{{ $data[$field['key']] ?? '' }}">{{ old('translations.'.$locale.'.'.$field['key'], $translations[$locale][$field['key']] ?? '') }}</textarea>
                    @else
                        <input id="tr_{{ $locale }}_{{ $field['key'] }}" type="text" name="translations[{{ $locale }}][{{ $field['key'] }}]"
                            placeholder="{{ $data[$field['key']] ?? '' }}"
                            value="{{ old('translations.'.$locale.'.'.$field['key'], $translations[$locale][$field['key']] ?? '') }}">
                    @endif
                </div>
            @endforeach
        @endforeach

        <div class="form-actions">
            <button type="submit">Save</button>
            <a href="{{ route('passports.show', $passport) }}">Cancel</a>
        </div>
    </form>
@endsection

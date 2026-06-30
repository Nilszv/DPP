@extends('layouts.admin')
@section('title', 'Edit legal document - DPP Admin')

@section('content')
    <p><a href="{{ route('admin.legal.index') }}">&larr; Legal documents</a></p>
    <h1>Edit: {{ $document->title }}</h1>
    <p class="muted">Current version: {{ $document->version }}. Changing the text bumps the version, and new acceptances record the new version.</p>

    <form method="POST" action="{{ route('admin.legal.update', $document) }}">
        @csrf
        @method('PUT')

        <div class="form-row">
            <label for="title">Title</label>
            <input id="title" name="title" type="text" value="{{ old('title', $document->title) }}" required>
            @error('title')<p class="field-error">{{ $message }}</p>@enderror
        </div>

        <div class="form-row">
            <label for="body">Policy text</label>
            <textarea id="body" name="body" rows="18" required>{{ old('body', $document->body) }}</textarea>
            @error('body')<p class="field-error">{{ $message }}</p>@enderror
        </div>

        <div class="form-row form-row-checkbox">
            <label>
                <input type="checkbox" name="requires_acceptance" value="1" @checked(old('requires_acceptance', $document->requires_acceptance))>
                Require new organizations to accept this at registration
            </label>
        </div>

        <div class="form-actions">
            <button type="submit">Save</button>
        </div>
    </form>
@endsection

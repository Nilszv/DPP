@extends('layouts.app')
@section('title', $passport->product->name.' - DPP Platform')

@section('content')
    <p><a href="{{ route('passports.index') }}">&larr; All passports</a></p>
    <h1>{{ $passport->product->name }}</h1>

    <p>
        Status: <span class="status-pill status-{{ $passport->status }}">{{ $passport->status }}</span>
    </p>

    <dl>
        <dt>Template</dt><dd>{{ $passport->product->template->name }}</dd>
        <dt>Identifier</dt><dd><code>{{ $passport->public_id }}</code></dd>
        <dt>Public URL</dt><dd><a href="{{ $passport->resolverUrl() }}">{{ $passport->resolverUrl() }}</a></dd>
        @if ($passport->isPublished())
            <dt>Published</dt><dd>{{ $passport->published_at?->toDayDateTimeString() }}</dd>
            <dt>Retention until</dt><dd>{{ $passport->retention_until }}</dd>
        @endif
    </dl>

    @if ($passport->isPublished())
        <h2>QR carrier</h2>
        <p class="muted">Scannable, smartphone-readable, no app needed. SVG scales to any print size.</p>
        <img src="{{ route('passports.qr', $passport) }}" alt="QR code" width="220" height="220">
        <p><a href="{{ route('passports.qr', $passport) }}" download="passport-qr.svg">Download QR (SVG)</a></p>
    @else
        <h2>Actions</h2>
        <p>
            <a class="button" href="{{ route('passports.edit', $passport) }}">Edit details</a>
        </p>
        <form method="POST" action="{{ route('passports.publish', $passport) }}">
            @csrf
            <button type="submit">Publish</button>
            <span class="muted">Publishing locks the data and makes the passport live.</span>
        </form>
    @endif
@endsection

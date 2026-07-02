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
        <h2>Corrections</h2>
        @if ($openCorrection)
            <p>
                A correction draft (v{{ $openCorrection->version_no }}) is in progress.
                The public page keeps serving v{{ $passport->currentVersion->version_no }} until the correction is published.
            </p>
            @if ($canCorrect)
                <p><a class="button" href="{{ route('passports.edit', $passport) }}">Edit correction</a></p>
                <form method="POST" action="{{ route('passports.corrections.publish', $passport) }}"
                      onsubmit="return confirm('Publish this correction? The public page will immediately serve the corrected data.')">
                    @csrf
                    <button type="submit">Publish correction</button>
                </form>
                <form method="POST" action="{{ route('passports.corrections.discard', $passport) }}"
                      onsubmit="return confirm('Discard this correction draft? Its changes will be lost. The published version is not affected.')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="button-secondary">Discard correction</button>
                </form>
            @endif
        @else
            <p class="muted">The published data is locked. To fix an error, start a correction: you edit a new version while the public page keeps serving the current one, then publish the correction to swap them.</p>
            @if ($canCorrect)
                <form method="POST" action="{{ route('passports.corrections.start', $passport) }}">
                    @csrf
                    <button type="submit">Start correction</button>
                </form>
            @endif
        @endif

        <h2>Version history</h2>
        <table>
            <thead>
                <tr><th>Version</th><th>Status</th><th>Created</th><th>By</th><th>Content hash</th></tr>
            </thead>
            <tbody>
                @foreach ($versions as $version)
                    <tr>
                        <td>v{{ $version->version_no }}</td>
                        <td>
                            @if ($version->id === $passport->current_version_id)
                                live
                            @elseif ($version->locked)
                                superseded
                            @else
                                correction draft
                            @endif
                        </td>
                        <td>{{ $version->created_at?->toDayDateTimeString() }}</td>
                        <td>{{ $version->creator?->name ?? '-' }}</td>
                        <td><code>{{ $version->locked ? substr($version->content_hash, 0, 12) : '(unlocked)' }}</code></td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <h2>QR carrier</h2>
        <p class="muted">Scannable, smartphone-readable, no app needed. SVG scales to any print size.</p>
        <img src="{{ route('passports.qr', $passport) }}" alt="QR code" width="220" height="220">
        <p><a href="{{ route('passports.qr', $passport) }}" download="passport-qr.svg">Download QR (SVG)</a></p>

        <h2>Tiered access links</h2>
        <p class="muted">Share these with repairers, recyclers, or authorities. Each link shows only the fields configured for that audience.</p>
        @foreach ($tierLinks as $tier)
            <div class="form-row">
                <label>{{ ucfirst($tier['audience']) }}</label>
                <div class="input-group">
                    <input type="text" readonly value="{{ $tier['url'] }}" data-copy-source>
                    <button type="button" data-copy-trigger>Copy</button>
                </div>
                @if ($canRegenerateTiers)
                    <form method="POST" action="{{ route('passports.tiers.regenerate', [$passport, $tier['audience']]) }}"
                          onsubmit="return confirm('Regenerate this link? The old link will stop working immediately.')">
                        @csrf
                        <button type="submit" class="button-secondary">Regenerate</button>
                    </form>
                @endif
            </div>
        @endforeach

        <script>
            document.querySelectorAll('[data-copy-trigger]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var input = btn.closest('.input-group').querySelector('[data-copy-source]');
                    navigator.clipboard.writeText(input.value).then(function () {
                        var original = btn.textContent;
                        btn.textContent = 'Copied!';
                        setTimeout(function () { btn.textContent = original; }, 1500);
                    });
                });
            });
        </script>
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

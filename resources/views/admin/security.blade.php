@extends('layouts.admin')
@section('title', 'Security - DPP Admin')

@section('content')
    <h1>Two-factor authentication</h1>

    <section>
        <dl>
            <dt>Status</dt>
            <dd>Enabled since {{ $confirmedAt?->toDayDateTimeString() }}</dd>
            <dt>Recovery codes remaining</dt>
            <dd>
                {{ $recoveryCodesRemaining }}
                @if ($recoveryCodesRemaining < 3)
                    <span class="field-error">Running low -- consider regenerating.</span>
                @endif
            </dd>
        </dl>
    </section>

    @if ($recoveryCodesOnce)
        <section>
            <h2>New recovery codes</h2>
            <p class="muted">Save these now -- they will not be shown again.</p>
            <pre class="recovery-codes">{{ implode("\n", $recoveryCodesOnce) }}</pre>
        </section>
    @endif

    <section>
        <h2>Regenerate recovery codes</h2>
        <p class="muted">Invalidates all current recovery codes and issues a fresh set.</p>
        <form method="POST" action="{{ route('admin.security.recovery-codes.regenerate') }}">
            @csrf
            <button type="submit">Regenerate recovery codes</button>
        </form>
    </section>

    <section>
        <h2>Reset two-factor setup</h2>
        <p class="muted">
            Confirm your current code to reset -- you'll be required to set up 2FA again before
            using any admin page.
        </p>
        <form method="POST" action="{{ route('admin.security.reset') }}">
            @csrf
            <div class="form-row">
                <label for="reset-code">Current 6-digit code</label>
                <input id="reset-code" name="code" type="text" inputmode="numeric"
                       pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" required>
                @error('code')
                    <p class="field-error" role="alert">{{ $message }}</p>
                @enderror
            </div>
            <button type="submit">Reset 2FA</button>
        </form>
    </section>
@endsection

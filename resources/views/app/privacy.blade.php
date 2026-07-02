<!DOCTYPE html>
{{-- Standalone page (like /app/support): reachable by suspended / half-onboarded users,
     whose GDPR rights don't depend on org state, so no org-context nav here. --}}
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Privacy &amp; data - DPP Platform</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body class="page page-app">
    <main class="app-main">
        <p><a href="{{ url('/app') }}">&larr; Back to app</a></p>
        <h1>Privacy &amp; data</h1>
        <p class="muted">Signed in as {{ $user->email }}</p>

        @if (session('status'))
            <p class="flash-status" role="status">{{ session('status') }}</p>
        @endif
        @if (session('error'))
            <p class="field-error" role="alert">{{ session('error') }}</p>
        @endif

        <h2>Export your data</h2>
        <p>Download a copy of the personal data this platform holds about you (profile,
            memberships, legal acceptances, invitations, and your own activity log) as JSON.</p>
        <p><a class="button" href="{{ route('privacy.export') }}">Download my data (JSON)</a></p>

        <h2>Delete your account</h2>
        @if ($impersonated)
            <p class="muted">Account deletion is not available from an impersonation session.</p>
        @elseif ($blocker)
            <p>Your account cannot be deleted automatically right now:</p>
            <p class="field-error">{{ $blocker }}</p>
            <p class="muted">You can resolve this yourself (e.g. reassign ownership) or
                <a href="{{ route('support.show') }}">contact support</a> - erasure requests are always honored, some just need manual handling.</p>
        @else
            <p>This permanently deletes your account and personal data: your profile, sign-in
                codes, invitations, active sessions, and your email wherever it appears in
                platform logs. Organizations where you are the only member are deleted with it.
                Legal-acceptance evidence is retained where the law requires it (without your
                account attached). <strong>This cannot be undone.</strong></p>

            <form method="POST" action="{{ route('privacy.erase') }}"
                  onsubmit="return confirm('Permanently delete your account and personal data? This cannot be undone.')">
                @csrf
                @method('DELETE')
                <div class="form-row">
                    <label for="confirm_email">Type your account email to confirm</label>
                    <input id="confirm_email" name="confirm_email" type="email" autocomplete="off" placeholder="{{ $user->email }}">
                    @error('confirm_email')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <button type="submit">Delete my account permanently</button>
            </form>
        @endif
    </main>
</body>
</html>

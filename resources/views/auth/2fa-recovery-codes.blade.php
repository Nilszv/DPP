<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your recovery codes - DPP Platform</title>
    {{-- Baseline layout only (public/css/app.css). A designer replaces it with the real design. --}}
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body class="page page-2fa-recovery-codes">
    <main class="auth">
        <h1 class="auth-title">Your recovery codes</h1>

        @if (empty($codes))
            <p class="auth-intro">
                These codes are no longer available to view (they're only shown once, right
                after they're generated). You can generate a fresh set from
                <a href="{{ route('admin.security.show') }}">Admin &rarr; Security</a>.
            </p>
        @else
            <p class="auth-intro">
                Save these somewhere safe -- each one lets you sign in once if you lose access to
                your authenticator app. They will not be shown again.
            </p>

            <pre class="recovery-codes">{{ implode("\n", $codes) }}</pre>
        @endif

        <div class="form-actions">
            <a class="button" href="{{ route('dashboard') }}">I've saved these codes, continue</a>
        </div>
    </main>
</body>
</html>

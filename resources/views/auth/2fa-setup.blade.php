<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Set up two-factor authentication - DPP Platform</title>
    {{-- Baseline layout only (public/css/app.css). A designer replaces it with the real design. --}}
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body class="page page-2fa-setup">
    <main class="auth">
        <h1 class="auth-title">Set up two-factor authentication</h1>
        <p class="auth-intro">
            Admin accounts require an authenticator app (Google Authenticator, Authy, 1Password,
            etc.). Scan this QR code, then enter the 6-digit code it shows to finish setup.
        </p>

        <div class="qr-code">{!! $qrSvg !!}</div>

        <p class="auth-intro">
            Can't scan it? Enter this key manually: <code>{{ $secret }}</code>
        </p>

        <form class="auth-form" method="POST" action="{{ route('2fa.setup.confirm') }}">
            @csrf
            <div class="form-row">
                <label for="code">6-digit code</label>
                <input id="code" name="code" type="text" inputmode="numeric"
                       pattern="[0-9]*" maxlength="6" autocomplete="one-time-code"
                       required autofocus>
                @error('code')
                    <p class="field-error" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div class="form-actions">
                <button type="submit">Confirm and continue</button>
            </div>
        </form>
    </main>
</body>
</html>

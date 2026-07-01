<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Two-factor code - DPP Platform</title>
    {{-- Baseline layout only (public/css/app.css). A designer replaces it with the real design. --}}
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body class="page page-2fa-verify">
    <main class="auth">
        <h1 class="auth-title">Enter your two-factor code</h1>
        <p class="auth-intro">Enter the 6-digit code from your authenticator app.</p>

        <form class="auth-form" method="POST" action="{{ $action }}">
            @csrf
            <div class="form-row">
                <label for="code">6-digit code</label>
                <input id="code" name="code" type="text" inputmode="numeric"
                       pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" autofocus>
            </div>

            <p class="auth-alt">Lost your device? Use a recovery code instead:</p>

            <div class="form-row">
                <label for="recovery_code">Recovery code</label>
                <input id="recovery_code" name="recovery_code" type="text" autocomplete="off">
            </div>

            @error('code')
                <p class="field-error" role="alert">{{ $message }}</p>
            @enderror

            <div class="form-actions">
                <button type="submit">Verify and continue</button>
            </div>
        </form>
    </main>
</body>
</html>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Enter your code - DPP Platform</title>
    {{-- No styles by design. --}}
</head>
<body class="page page-verify">
    <main class="auth">
        <h1 class="auth-title">Enter your login code</h1>
        <p class="auth-intro">
            We sent a 6-digit code to <strong>{{ $email }}</strong>.
            Enter it below. The code expires in a few minutes and can be used once.
        </p>

        @if (session('status'))
            <p class="flash flash-status" role="status">{{ session('status') }}</p>
        @endif

        <form class="auth-form" method="POST" action="{{ route('login.verify') }}">
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
                <button type="submit">Verify and continue</button>
            </div>
        </form>

        <p class="auth-alt">
            Wrong email or no code? <a href="{{ route('login') }}">Start over</a>.
        </p>
    </main>
</body>
</html>

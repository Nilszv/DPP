<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log in - DPP Platform</title>
    {{-- No styles by design. Semantic markup for the designer to style later. --}}
</head>
<body class="page page-login">
    <main class="auth">
        <h1 class="auth-title">Log in to DPP Platform</h1>
        <p class="auth-intro">
            Enter your email and we will send you a 6-digit code. No password needed.
            If you do not have an account yet, one is created on first login.
        </p>

        @if (session('status'))
            <p class="flash flash-status" role="status">{{ session('status') }}</p>
        @endif

        <form class="auth-form" method="POST" action="{{ route('login.send') }}">
            @csrf
            <div class="form-row">
                <label for="email">Email address</label>
                <input id="email" name="email" type="email" autocomplete="email"
                       required autofocus value="{{ old('email') }}">
                @error('email')
                    <p class="field-error" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div class="form-actions">
                <button type="submit">Send login code</button>
            </div>
        </form>
    </main>
</body>
</html>

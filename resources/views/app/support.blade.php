<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Contact support - DPP Platform</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body class="page page-support">
    <main class="site-main">
        @if ($suspended)
            <h1>Your account needs review</h1>
            <div class="notice notice-error" role="alert">
                <p>
                    We could not complete your registration because the company details you
                    entered match an organization that is already registered with us. To prevent
                    duplicate accounts, this account has been paused.
                </p>
                <p>
                    If you believe this is a mistake, please contact our support team using the
                    form below and we will help you sort it out. Your account stays paused until
                    support resolves the issue.
                </p>
            </div>
        @else
            <h1>Contact support</h1>
            <p class="muted">Need help? Send us a message and our team will get back to you.</p>
        @endif

        @if (session('status'))
            <div class="notice notice-success" role="status">{{ session('status') }}</div>
        @endif

        <form method="POST" action="{{ route('support.send') }}">
            @csrf

            <div class="form-row">
                <label for="company_name">Company name</label>
                <input id="company_name" name="company_name" type="text"
                       value="{{ old('company_name', $companyName) }}" required>
                @error('company_name')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="form-row">
                <label for="email">Contact email</label>
                <input id="email" name="email" type="email"
                       value="{{ old('email', $user->email) }}" required>
                @error('email')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="form-row">
                <label for="phone">Contact phone</label>
                <input id="phone" name="phone" type="tel" value="{{ old('phone', $phone) }}" required>
                @error('phone')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="form-row">
                <label for="message">Message</label>
                <textarea id="message" name="message" rows="6" required>{{ old('message') }}</textarea>
                @error('message')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="form-actions">
                <button type="submit">Send to support</button>
            </div>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="button-secondary">Log out</button>
        </form>
    </main>
</body>
</html>

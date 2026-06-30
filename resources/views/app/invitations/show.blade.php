<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Team invitation - DPP Platform</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body class="page page-invitation">
    <main class="auth">
        <h1>Team invitation</h1>

        @if (! $invitation)
            <p class="field-error">This invitation is invalid, already used, or expired.</p>
            <p><a href="{{ route('dashboard') }}">Go to the app</a></p>
        @elseif (! $matchesEmail)
            <p class="field-error">
                This invitation was sent to <strong>{{ $invitation->email }}</strong>, but you are signed in as
                <strong>{{ auth()->user()->email }}</strong>.
            </p>
            <p>Log out and sign in with {{ $invitation->email }} to accept.</p>
            <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit">Log out</button></form>
        @else
            <p>
                You have been invited to join <strong>{{ $invitation->organization->name }}</strong>
                as <strong>{{ $invitation->role }}</strong>.
            </p>
            <form method="POST" action="{{ route('invitations.accept', $invitation->token) }}">
                @csrf
                <button type="submit">Accept and join</button>
            </form>
        @endif
    </main>
</body>
</html>

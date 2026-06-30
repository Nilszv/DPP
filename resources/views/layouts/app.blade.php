<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'DPP Platform')</title>
    {{-- Baseline layout only (public/css/app.css). A designer replaces it with the real design. --}}
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body class="page">
    <header class="app-header">
        <strong class="app-title"><a href="{{ route('dashboard') }}">DPP Platform</a></strong>
        <nav class="app-nav" aria-label="Primary">
            <a href="{{ route('passports.index') }}">Passports</a>
            <span class="muted">{{ auth()->user()?->email }}</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit">Log out</button>
            </form>
        </nav>
    </header>

    <main class="app-main">
        @if (session('status'))
            <p class="flash-status" role="status">{{ session('status') }}</p>
        @endif
        @if (session('error'))
            <p class="field-error" role="alert">{{ session('error') }}</p>
        @endif

        @yield('content')
    </main>
</body>
</html>

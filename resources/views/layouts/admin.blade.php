<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Admin - DPP Platform')</title>
    {{-- Baseline layout only (public/css/app.css). A designer replaces it later. --}}
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body class="page page-admin">
    <header class="app-header">
        <strong class="app-title"><a href="{{ route('admin.overview') }}">DPP Admin</a></strong>
        <nav class="app-nav" aria-label="Admin">
            <a href="{{ route('admin.overview') }}">Overview</a>
            <a href="{{ route('admin.organizations') }}">Organizations</a>
            <a href="{{ route('admin.passports.index') }}">QR codes</a>
            <a href="{{ route('admin.plans.index') }}">Plans</a>
            <a href="{{ route('admin.legal.index') }}">Legal</a>
            <a href="{{ route('admin.audit.index') }}">Audit</a>
            <a href="{{ route('admin.security.show') }}">Security</a>
            <a href="{{ route('dashboard') }}">Back to app</a>
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

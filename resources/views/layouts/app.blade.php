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
            <a href="{{ route('team.index') }}">Team</a>
            <a href="{{ route('organization.show') }}">Company</a>
            <a href="{{ route('billing.index') }}">Plan</a>
            <a href="{{ route('privacy.show') }}">Privacy</a>
            @if (auth()->user()?->isAdmin())
                <a href="{{ route('admin.overview') }}">Admin</a>
            @endif
            @php($myOrgs = auth()->user()?->organizations)
            @if ($myOrgs && $myOrgs->count() > 1)
                <form method="POST" action="{{ route('current-org.switch') }}">
                    @csrf
                    <select name="organization_id" onchange="this.form.submit()" aria-label="Switch organization">
                        @foreach ($myOrgs as $o)
                            <option value="{{ $o->id }}" @selected($o->id === auth()->user()->current_organization_id)>{{ $o->name }}</option>
                        @endforeach
                    </select>
                </form>
            @endif
            <span class="muted">{{ auth()->user()?->email }}</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit">Log out</button>
            </form>
        </nav>
    </header>

    <main class="app-main">
        @if (session('impersonate.original_admin_id'))
            <p class="impersonation-banner" role="status">
                You are impersonating {{ auth()->user()->email }}.
                <form method="POST" action="{{ route('impersonate.stop') }}" style="display:inline">
                    @csrf
                    <button type="submit">Stop impersonating</button>
                </form>
            </p>
        @endif
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

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard - DPP Platform</title>
    {{-- No styles by design. --}}
</head>
@php($org = auth()->user()?->organizations->firstWhere('id', auth()->user()->current_organization_id))
<body class="page page-dashboard">
    <header class="app-header">
        <h1 class="app-title">DPP Platform</h1>
        <nav class="app-nav" aria-label="Account">
            <span class="app-user">{{ auth()->user()->email }}</span>
            <form method="POST" action="{{ route('logout') }}" class="logout-form">
                @csrf
                <button type="submit">Log out</button>
            </form>
        </nav>
    </header>

    <main class="app-main">
        <h2>Welcome, {{ auth()->user()->name }}</h2>

        @if ($org)
            <section class="org-summary">
                <h3>Your organization</h3>
                <dl>
                    <dt>Name</dt><dd>{{ $org->name }}</dd>
                    <dt>Plan</dt><dd>{{ ucfirst($org->plan) }}</dd>
                    <dt>Published DPP quota</dt>
                    <dd>{{ $org->publishedQuota() === PHP_INT_MAX ? 'Custom' : $org->publishedQuota() }}</dd>
                </dl>
            </section>
        @endif

        <section class="next-steps">
            <h3>Next steps</h3>
            <p class="muted">
                Product and passport creation are being built next. This dashboard will list
                your products, draft and published passports, and scan counts.
            </p>
        </section>
    </main>
</body>
</html>

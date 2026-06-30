<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>DPP Platform</title>
    {{-- No styles by design. A designer will add SCSS later over this semantic markup. --}}
</head>
<body class="page page-landing">
    <header class="site-header">
        <h1 class="site-title">DPP Platform</h1>
        <p class="site-tagline">Digital Product Passports - create, host, and resolve.</p>
    </header>

    <main class="site-main">
        <section class="landing-intro">
            <p>
                This is the <strong>platform application</strong>. In production the public
                marketing site (WordPress) will own <code>/</code>; the platform will live at
                <code>/login</code>, <code>/app</code>, and the public passport viewer at
                <code>/p/{id}</code>.
            </p>
        </section>

        <nav class="landing-nav" aria-label="Primary">
            <ul>
                <li><a href="/login">Log in</a> <span class="muted">(coming next)</span></li>
                <li><a href="/health">Service health</a></li>
            </ul>
        </nav>

        <section class="landing-status">
            <h2>Build status</h2>
            <p class="muted">
                Slice 1 (core loop) in progress - database foundation complete.
                See <code>docs/STATUS.md</code> for what's done vs. pending.
            </p>
        </section>
    </main>

    <footer class="site-footer">
        <p class="muted">DPP Platform · development build</p>
    </footer>
</body>
</html>

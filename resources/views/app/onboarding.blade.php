<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Complete registration - DPP Platform</title>
    {{-- Baseline layout only (public/css/app.css). A designer replaces it later. --}}
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body class="page page-onboarding">
    <main class="site-main">
        <h1>Complete your registration</h1>
        <p class="muted">
            We need your company details and your agreement to our policies before you can create
            passports. Published passports are a 10+ year hosting commitment, so this step is required.
        </p>

        @if ($errors->any())
            <p class="field-error" role="alert">Please correct the highlighted fields below.</p>
        @endif

        <form method="POST" action="{{ route('onboarding.store') }}">
            @csrf

            <h2>Company details</h2>
            @include('app.partials.company-fields', ['org' => $org, 'countries' => $countries])

            <h2>Policies</h2>
            <p class="muted">Scroll through each policy, then tick the box to accept it.</p>

            @foreach ($documents as $doc)
                <section class="legal-doc">
                    <h3>{{ $doc->title }}</h3>
                    <div class="legal-body" data-key="{{ $doc->key }}" tabindex="0">{{ $doc->body }}</div>
                    <div class="form-row form-row-checkbox">
                        <label>
                            <input type="checkbox" name="accept[{{ $doc->key }}]" value="1"
                                   id="chk-{{ $doc->key }}" required disabled>
                            I have read and accept the {{ $doc->title }}.
                        </label>
                        @error("accept.{$doc->key}")<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                </section>
            @endforeach

            <div class="form-actions">
                <button type="submit">Complete registration</button>
            </div>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="button-secondary">Log out</button>
        </form>
    </main>

    <script>
        // Enforce reading: enable each policy checkbox only once its text is scrolled to the end
        // (or if it is short enough not to need scrolling). Server-side acceptance is still required.
        document.querySelectorAll('.legal-body').forEach(function (box) {
            var checkbox = document.getElementById('chk-' + box.getAttribute('data-key'));
            function maybeEnable() {
                if (box.scrollTop + box.clientHeight >= box.scrollHeight - 4) {
                    checkbox.disabled = false;
                }
            }
            box.addEventListener('scroll', maybeEnable);
            if (box.scrollHeight <= box.clientHeight + 4) { checkbox.disabled = false; }
        });
    </script>
</body>
</html>

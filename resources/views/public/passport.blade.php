<!DOCTYPE html>
<html lang="{{ $currentLocale ?? ($p['locale'] ?? 'en') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $p['title'] ?? __('public.digital_product_passport') }}</title>
    {{-- Baseline layout only (public/css/app.css). A designer replaces it with the real design. --}}
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body class="page page-passport">
    <main class="passport">
        @if (! empty($localeUrls))
            <nav class="lang-switch muted" aria-label="{{ __('public.language') }}">
                @foreach ($localeUrls as $locale => $url)
                    @if ($locale === ($currentLocale ?? null))
                        <strong>{{ __('public.locales.'.$locale) }}</strong>
                    @else
                        <a href="{{ $url }}" rel="nofollow">{{ __('public.locales.'.$locale) }}</a>
                    @endif
                    @if (! $loop->last) &middot; @endif
                @endforeach
            </nav>
        @endif

        <p class="muted">{{ __('public.digital_product_passport') }}</p>
        @if (($p['audience'] ?? 'consumer') !== 'consumer')
            <p class="muted">{{ __('public.viewing', ['audience' => __('public.audiences.'.$p['audience'])]) }}</p>
        @endif
        <h1>{{ $p['title'] ?? 'Product' }}</h1>

        @if (empty($p['fields']))
            <p class="muted">{{ __('public.no_details') }}</p>
        @else
            <dl>
                @foreach ($p['fields'] as $field)
                    <dt>{{ $field['label'] }}</dt>
                    <dd>{{ $field['value'] }}</dd>
                @endforeach
            </dl>
        @endif

        <hr>
        <p class="muted">
            {{ __('public.identifier') }}: <code>{{ $p['identifier']['public_id'] ?? '' }}</code><br>
            {{-- Two distinct guarantees: the locked source-language master record, and the
                 exact per-locale payload this page renders (translations included). --}}
            {{ __('public.source_record_hash') }}: <code>{{ substr($p['content_hash'] ?? '', 0, 16) }}...</code><br>
            {{ __('public.page_content_hash') }}: <code>{{ substr($etag ?? '', 0, 16) }}...</code>
        </p>
    </main>
</body>
</html>

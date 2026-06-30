<!DOCTYPE html>
<html lang="{{ $p['locale'] ?? 'en' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $p['title'] ?? 'Digital Product Passport' }}</title>
    {{-- Baseline layout only (public/css/app.css). A designer replaces it with the real design. --}}
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body class="page page-passport">
    <main class="passport">
        <p class="muted">Digital Product Passport</p>
        <h1>{{ $p['title'] ?? 'Product' }}</h1>

        @if (empty($p['fields']))
            <p class="muted">No public details are available for this product.</p>
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
            Identifier: <code>{{ $p['identifier']['public_id'] ?? '' }}</code><br>
            Verified content hash: <code>{{ substr($p['content_hash'] ?? '', 0, 16) }}...</code>
        </p>
    </main>
</body>
</html>

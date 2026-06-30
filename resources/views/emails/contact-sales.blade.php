<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Contact sales</title></head>
<body>
    <p>A customer has requested to contact sales.</p>
    <p>
        <strong>Organization:</strong> {{ $organization->name }} (plan: {{ $organization->plan }})<br>
        <strong>From:</strong> {{ $user->name }} &lt;{{ $user->email }}&gt;<br>
        <strong>Published passports:</strong> {{ $organization->publishedCount() }}<br>
        @if ($interest)<strong>Interested in:</strong> {{ $interest }}<br>@endif
    </p>
    <p><strong>Message:</strong></p>
    <p>{!! nl2br(e($body)) !!}</p>
</body>
</html>

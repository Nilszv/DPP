<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Support request</title></head>
<body>
    <p>A user has submitted a support request from the in-app support page.</p>

    <p>
        <strong>Company:</strong> {{ $companyName }}<br>
        <strong>Contact email:</strong> {{ $email }}<br>
        <strong>Contact phone:</strong> {{ $phone }}<br>
        <strong>Account:</strong> {{ $user->name }} &lt;{{ $user->email }}&gt;<br>
    </p>

    <p><strong>Message:</strong></p>
    <p>{!! nl2br(e($body)) !!}</p>

    @if ($adminReason)
        <hr>
        <p><strong>Account status (admin only):</strong></p>
        <p>{!! nl2br(e($adminReason)) !!}</p>
    @endif
</body>
</html>

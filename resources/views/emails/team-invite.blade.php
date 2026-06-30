<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Team invitation</title></head>
<body>
    <p>Hello,</p>
    <p>
        You have been invited to join <strong>{{ $invitation->organization->name }}</strong>
        on DPP Platform as <strong>{{ $invitation->role }}</strong>.
    </p>
    <p>To accept, open this link and sign in with this email address ({{ $invitation->email }}):</p>
    <p><a href="{{ $acceptUrl }}">{{ $acceptUrl }}</a></p>
    <p>This invitation expires on {{ $invitation->expires_at->toDayDateString() }}.</p>
    <p>If you did not expect this, you can ignore this email.</p>
    <p>DPP Platform</p>
</body>
</html>

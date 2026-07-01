<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Account suspended: duplicate registration</title></head>
<body>
    <p>An email account has been automatically suspended after repeated attempts to register
       a duplicate of an existing organization. It needs support to review and resolve.</p>

    <p>
        <strong>Suspended user:</strong> {{ $user->name }} &lt;{{ $user->email }}&gt;<br>
        <strong>Blocked attempts:</strong> {{ $attempts }}<br>
    </p>

    <p><strong>Matched existing organization (admin only):</strong></p>
    <p>
        {{ $matchedOrganization->name }}<br>
        Company name: {{ $matchedOrganization->legal_name }}<br>
        Country: {{ $matchedOrganization->country }}<br>
        Registration number: {{ $matchedOrganization->registration_number }}<br>
        VAT number: {{ $matchedOrganization->vat_id }}<br>
        Org id: {{ $matchedOrganization->id }}
    </p>

    <p><strong>Reason (admin only):</strong></p>
    <p>{!! nl2br(e($reason)) !!}</p>

    <p>To resolve: open the organization in the admin back-office and lift the suspension on
       this user once the situation is clarified.</p>
</body>
</html>

@extends('layouts.app')
@section('title', 'Team - DPP Platform')

@section('content')
    <h1>Team</h1>
    <p class="muted">
        {{ $usedSeats }} of {{ $seatLimit === PHP_INT_MAX ? 'unlimited' : $seatLimit }} seats used
        (members + pending invitations).
    </p>

    <section>
        <h2>Members</h2>
        <table>
            <thead>
                <tr><th>Name</th><th>Email</th><th>Role</th>@if ($canManage)<th></th>@endif</tr>
            </thead>
            <tbody>
                @foreach ($members as $member)
                    <tr>
                        <td>{{ $member->name }}</td>
                        <td>{{ $member->email }}</td>
                        <td>{{ $member->pivot->role }}</td>
                        @if ($canManage)
                            <td>
                                <form method="POST" action="{{ route('team.members.role', $member) }}">
                                    @csrf @method('PUT')
                                    <select name="role">
                                        @foreach (['owner', 'admin', 'editor', 'viewer'] as $role)
                                            <option value="{{ $role }}" @selected($member->pivot->role === $role)>{{ $role }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit">Update</button>
                                </form>
                                <form method="POST" action="{{ route('team.members.remove', $member) }}"
                                      onsubmit="return confirm('Remove {{ $member->email }}?')">
                                    @csrf @method('DELETE')
                                    <button type="submit">Remove</button>
                                </form>
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    @if ($invitations->isNotEmpty())
        <section>
            <h2>Pending invitations</h2>
            <table>
                <thead><tr><th>Email</th><th>Role</th><th>Expires</th>@if ($canManage)<th></th>@endif</tr></thead>
                <tbody>
                    @foreach ($invitations as $invite)
                        <tr>
                            <td>{{ $invite->email }}</td>
                            <td>{{ $invite->role }}</td>
                            <td>{{ $invite->expires_at->toFormattedDateString() }}</td>
                            @if ($canManage)
                                <td>
                                    <form method="POST" action="{{ route('team.invitations.revoke', $invite) }}">
                                        @csrf @method('DELETE')
                                        <button type="submit">Revoke</button>
                                    </form>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    @if ($canManage)
        <section>
            <h2>Invite a teammate</h2>
            @if ($org->hasSeatAvailable())
                <form method="POST" action="{{ route('team.invite') }}">
                    @csrf
                    <div class="form-row">
                        <label for="email">Email</label>
                        <input id="email" name="email" type="email" required value="{{ old('email') }}">
                        @error('email')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="form-row">
                        <label for="role">Role</label>
                        <select id="role" name="role">
                            @foreach (['admin', 'editor', 'viewer'] as $role)
                                <option value="{{ $role }}" @selected(old('role') === $role)>{{ $role }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-actions">
                        <button type="submit">Send invitation</button>
                    </div>
                </form>
            @else
                <p class="muted">
                    You have used all your seats. <a href="{{ route('billing.index') }}">Upgrade your plan</a> for more.
                </p>
            @endif
        </section>
    @endif
@endsection

@extends('layouts.admin')
@section('title', 'Organizations - DPP Admin')

@section('content')
    <h1>Organizations</h1>

    <table>
        <thead>
            <tr><th>Name</th><th>Plan</th><th>Quota</th><th>Members</th><th>Passports</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
            @foreach ($organizations as $org)
                <tr>
                    <td>{{ $org->name }}</td>
                    <td>{{ $org->planName() }}</td>
                    <td>{{ $org->publishedQuota() === PHP_INT_MAX ? 'Unlimited' : $org->publishedQuota() }}@if ($org->published_quota_override !== null) <span class="muted">(override)</span>@endif</td>
                    <td>{{ $org->members_count }}</td>
                    <td>{{ $org->passports_count }}</td>
                    <td>{{ $org->status }}</td>
                    <td><a href="{{ route('admin.organizations.edit', $org) }}">Edit</a></td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endsection

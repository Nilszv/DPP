@extends('layouts.admin')
@section('title', 'Overview - DPP Admin')

@section('content')
    <h1>Platform overview</h1>

    <section>
        <h2>At a glance</h2>
        <dl>
            <dt>Organizations</dt><dd>{{ $stats['organizations'] }}</dd>
            <dt>Users</dt><dd>{{ $stats['users'] }}</dd>
            <dt>Published passports</dt><dd>{{ $stats['passports_published'] }}</dd>
            <dt>Draft passports</dt><dd>{{ $stats['passports_draft'] }}</dd>
            <dt>Scans (all time)</dt><dd>{{ $stats['scans_total'] }}</dd>
            <dt>Scans (last 30 days)</dt><dd>{{ $stats['scans_30d'] }}</dd>
        </dl>
    </section>

    <section>
        <h2>Plan distribution</h2>
        <table>
            <thead><tr><th>Plan</th><th>Organizations</th></tr></thead>
            <tbody>
                @foreach ($planDistribution as $plan => $total)
                    <tr><td>{{ ucfirst($plan) }}</td><td>{{ $total }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </section>
@endsection

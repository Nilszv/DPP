@extends('layouts.app')
@section('title', 'Dashboard - DPP Platform')

@php($org = auth()->user()?->organizations->firstWhere('id', auth()->user()->current_organization_id))

@section('content')
    <h1>Welcome, {{ auth()->user()->name }}</h1>

    @if ($org)
        <section class="org-summary">
            <h2>Your organization</h2>
            <dl>
                <dt>Name</dt><dd>{{ $org->name }}</dd>
                <dt>Plan</dt><dd>{{ ucfirst($org->plan) }}</dd>
                <dt>Published DPP quota</dt>
                <dd>{{ $org->publishedQuota() === PHP_INT_MAX ? 'Custom' : $org->publishedQuota() }}</dd>
            </dl>
        </section>
    @endif

    <section class="next-steps">
        <h2>Get started</h2>
        <p><a class="button" href="{{ route('passports.create') }}">Create a passport</a></p>
        <p><a href="{{ route('passports.index') }}">View all passports</a></p>
    </section>
@endsection

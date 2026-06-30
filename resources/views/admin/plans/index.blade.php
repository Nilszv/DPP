@extends('layouts.admin')
@section('title', 'Plans - DPP Admin')

@section('content')
    <h1>Plans</h1>
    <p><a class="button" href="{{ route('admin.plans.create') }}">New plan</a></p>

    <table>
        <thead>
            <tr><th>Key</th><th>Name</th><th>Price</th><th>Interval</th><th>Quota</th><th>Public</th><th>Active</th><th></th></tr>
        </thead>
        <tbody>
            @foreach ($plans as $plan)
                <tr>
                    <td><code>{{ $plan->key }}</code></td>
                    <td>{{ $plan->name }}</td>
                    <td>{{ $plan->price === null ? 'custom' : $plan->price }}</td>
                    <td>{{ $plan->interval ?? '-' }}</td>
                    <td>{{ $plan->published_quota === null ? 'unlimited' : $plan->published_quota }}</td>
                    <td>{{ $plan->is_public ? 'yes' : 'no' }}</td>
                    <td>{{ $plan->active ? 'yes' : 'no' }}</td>
                    <td><a href="{{ route('admin.plans.edit', $plan) }}">Edit</a></td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endsection

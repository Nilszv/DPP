@extends('layouts.app')
@section('title', 'Passports - DPP Platform')

@section('content')
    <h1>Passports</h1>
    <p><a class="button" href="{{ route('passports.create') }}">New passport</a></p>

    @if ($passports->isEmpty())
        <p class="muted">No passports yet. Create your first one to get a scannable Digital Product Passport.</p>
    @else
        <table>
            <thead>
                <tr><th>Product</th><th>Status</th><th>Identifier</th><th></th></tr>
            </thead>
            <tbody>
                @foreach ($passports as $passport)
                    <tr>
                        <td>{{ $passport->product->name }}</td>
                        <td><span class="status-pill status-{{ $passport->status }}">{{ $passport->status }}</span></td>
                        <td><code>{{ $passport->public_id }}</code></td>
                        <td><a href="{{ route('passports.show', $passport) }}">Open</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection

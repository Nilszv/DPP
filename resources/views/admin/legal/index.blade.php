@extends('layouts.admin')
@section('title', 'Legal documents - DPP Admin')

@section('content')
    <h1>Legal documents</h1>
    <p class="muted">These texts are shown during registration and must be accepted by new organizations.</p>

    <table>
        <thead>
            <tr><th>Title</th><th>Key</th><th>Version</th><th>Required at signup</th><th></th></tr>
        </thead>
        <tbody>
            @foreach ($documents as $doc)
                <tr>
                    <td>{{ $doc->title }}</td>
                    <td><code>{{ $doc->key }}</code></td>
                    <td>{{ $doc->version }}</td>
                    <td>{{ $doc->requires_acceptance ? 'yes' : 'no' }}</td>
                    <td><a href="{{ route('admin.legal.edit', $doc) }}">Edit</a></td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endsection

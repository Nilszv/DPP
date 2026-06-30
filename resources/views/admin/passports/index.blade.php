@extends('layouts.admin')
@section('title', 'QR codes - DPP Admin')

@section('content')
    <h1>QR codes / passports</h1>

    <form method="GET" action="{{ route('admin.passports.index') }}" class="filters">
        <div class="form-row">
            <label for="q">Search (public id, GTIN, serial, product)</label>
            <input id="q" name="q" type="text" value="{{ $filters['q'] }}">
        </div>
        <div class="form-row">
            <label for="org">Organization</label>
            <select id="org" name="org">
                <option value="">All organizations</option>
                @foreach ($organizations as $org)
                    <option value="{{ $org->id }}" @selected($filters['org'] === $org->id)>{{ $org->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-row">
            <label for="status">Status</label>
            <select id="status" name="status">
                @foreach (['' => 'Any', 'draft' => 'draft', 'published' => 'published', 'archived' => 'archived'] as $val => $label)
                    <option value="{{ $val }}" @selected($filters['status'] === ($val ?: null))>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-actions">
            <button type="submit">Filter</button>
            <a href="{{ route('admin.passports.index') }}">Reset</a>
        </div>
    </form>

    <p class="muted">{{ $passports->total() }} total. Showing {{ $passports->firstItem() ?? 0 }}-{{ $passports->lastItem() ?? 0 }}.</p>

    @if ($passports->isEmpty())
        <p class="muted">No passports match.</p>
    @else
        <table>
            <thead>
                <tr><th>QR</th><th>Product</th><th>Organization</th><th>Status</th><th>Identifier</th><th></th></tr>
            </thead>
            <tbody>
                @foreach ($passports as $passport)
                    <tr>
                        <td><img src="{{ route('admin.passports.qr', $passport->id) }}" alt="QR" width="64" height="64" loading="lazy"></td>
                        <td>{{ $passport->product->name }}</td>
                        <td>{{ $passport->organization->name }}</td>
                        <td><span class="status-pill status-{{ $passport->status }}">{{ $passport->status }}</span></td>
                        <td><code>{{ $passport->gtin ? $passport->gtin.' / '.$passport->serial : $passport->public_id }}</code></td>
                        <td>
                            <a href="{{ route('admin.passports.qr', $passport->id) }}" target="_blank">QR</a>
                            @if ($passport->isPublished())
                                &middot; <a href="{{ $passport->resolverUrl() }}" target="_blank">Public</a>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if ($passports->hasPages())
            <nav class="pagination" aria-label="Pagination">
                @if ($passports->onFirstPage())
                    <span class="muted">Previous</span>
                @else
                    <a href="{{ $passports->previousPageUrl() }}">Previous</a>
                @endif
                <span class="muted">Page {{ $passports->currentPage() }} of {{ $passports->lastPage() }}</span>
                @if ($passports->hasMorePages())
                    <a href="{{ $passports->nextPageUrl() }}">Next</a>
                @else
                    <span class="muted">Next</span>
                @endif
            </nav>
        @endif
    @endif
@endsection

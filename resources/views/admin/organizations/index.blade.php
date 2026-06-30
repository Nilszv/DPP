@extends('layouts.admin')
@section('title', 'Organizations - DPP Admin')

@section('content')
    <h1>Organizations</h1>

    <form method="GET" action="{{ route('admin.organizations') }}" class="filters">
        <div class="form-row">
            <label for="q">Search (company name, contact email, member email)</label>
            <input id="q" name="q" type="text" value="{{ $filters['q'] }}">
        </div>
        <div class="form-row">
            <label for="plan">Plan</label>
            <select id="plan" name="plan">
                <option value="">Any</option>
                @foreach ($plans as $plan)
                    <option value="{{ $plan->key }}" @selected($filters['plan'] === $plan->key)>{{ $plan->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-row">
            <label for="status">Status</label>
            <select id="status" name="status">
                @foreach (['' => 'Any', 'active' => 'active', 'suspended' => 'suspended'] as $val => $label)
                    <option value="{{ $val }}" @selected($filters['status'] === ($val ?: null))>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-row">
            <label for="sort">Sort by</label>
            <select id="sort" name="sort">
                @foreach (['created' => 'Newest', 'name' => 'Company name', 'published' => 'Published count'] as $val => $label)
                    <option value="{{ $val }}" @selected($filters['sort'] === $val)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-row">
            <label for="dir">Direction</label>
            <select id="dir" name="dir">
                <option value="desc" @selected($filters['dir'] === 'desc')>Descending</option>
                <option value="asc" @selected($filters['dir'] === 'asc')>Ascending</option>
            </select>
        </div>
        <div class="form-actions">
            <button type="submit">Apply</button>
            <a href="{{ route('admin.organizations') }}">Reset</a>
        </div>
    </form>

    <p class="muted">{{ $organizations->total() }} total. Showing {{ $organizations->firstItem() ?? 0 }}-{{ $organizations->lastItem() ?? 0 }}.</p>

    <table>
        <thead>
            <tr><th>Company</th><th>Plan</th><th>Quota</th><th>Members</th><th>Published</th><th>Country</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
            @foreach ($organizations as $org)
                <tr>
                    <td>{{ $org->legal_name ?? $org->name }}</td>
                    <td>{{ $org->planName() }}</td>
                    <td>{{ $org->publishedQuota() === PHP_INT_MAX ? 'Unlimited' : $org->publishedQuota() }}</td>
                    <td>{{ $org->members_count }}</td>
                    <td>{{ $org->published_count }}</td>
                    <td>{{ $org->country ?: '-' }}</td>
                    <td>{{ $org->status }}</td>
                    <td>
                        <a href="{{ route('admin.organizations.show', $org) }}">View</a>
                        &middot; <a href="{{ route('admin.organizations.edit', $org) }}">Edit</a>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($organizations->hasPages())
        <nav class="pagination" aria-label="Pagination">
            @if ($organizations->onFirstPage())
                <span class="muted">Previous</span>
            @else
                <a href="{{ $organizations->previousPageUrl() }}">Previous</a>
            @endif
            <span class="muted">Page {{ $organizations->currentPage() }} of {{ $organizations->lastPage() }}</span>
            @if ($organizations->hasMorePages())
                <a href="{{ $organizations->nextPageUrl() }}">Next</a>
            @else
                <span class="muted">Next</span>
            @endif
        </nav>
    @endif
@endsection

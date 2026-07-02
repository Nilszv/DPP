@extends('layouts.admin')
@section('title', 'Audit trail - DPP Admin')

@section('content')
    <h1>Audit trail</h1>
    <p class="muted">Append-only record of sensitive platform actions (impersonations, correction publishes, ...). Rows are never edited or deleted.</p>

    <form method="GET" action="{{ route('admin.audit.index') }}" class="filters">
        <div class="form-row">
            <label for="action">Action</label>
            <select id="action" name="action">
                <option value="">All actions</option>
                @foreach ($actions as $action)
                    <option value="{{ $action }}" @selected($filters['action'] === $action)>{{ $action }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-row">
            <label for="actor">Actor (name or email)</label>
            <input id="actor" name="actor" type="text" value="{{ $filters['actor'] }}">
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
            <label for="from">From</label>
            <input id="from" name="from" type="date" value="{{ $filters['from'] }}">
        </div>
        <div class="form-row">
            <label for="to">To</label>
            <input id="to" name="to" type="date" value="{{ $filters['to'] }}">
        </div>
        <div class="form-actions">
            <button type="submit">Filter</button>
            <a href="{{ route('admin.audit.index') }}">Reset</a>
        </div>
    </form>

    <p class="muted">{{ $entries->total() }} total. Showing {{ $entries->firstItem() ?? 0 }}-{{ $entries->lastItem() ?? 0 }}.</p>

    @if ($entries->isEmpty())
        <p class="muted">No audit entries match.</p>
    @else
        <table>
            <thead>
                <tr><th>When (UTC)</th><th>Action</th><th>Actor</th><th>Organization</th><th>Target</th><th>Details</th></tr>
            </thead>
            <tbody>
                @foreach ($entries as $entry)
                    <tr>
                        <td>{{ $entry->ts?->format('Y-m-d H:i:s') }}</td>
                        <td><code>{{ $entry->action }}</code></td>
                        <td>
                            @if ($entry->actor)
                                {{ $entry->actor->name }} <span class="muted">({{ $entry->actor->email }})</span>
                            @else
                                <span class="muted">system / deleted user</span>
                            @endif
                        </td>
                        <td>{{ $entry->organization?->name ?? '-' }}</td>
                        <td><code>{{ $entry->target ?? '-' }}</code></td>
                        <td>
                            @if (! empty($entry->meta))
                                <details>
                                    <summary class="muted">meta</summary>
                                    <pre>{{ json_encode($entry->meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                </details>
                            @else
                                <span class="muted">-</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{ $entries->links() }}
    @endif
@endsection

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Read-only browser over the append-only audit trail (impersonations, correction publishes,
 * ...). Platform-wide, super-admin only, always paginated -- audit_log is month-partitioned
 * and grows forever, so nothing here may ever load it unbounded.
 */
class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $query = AuditLog::with(['actor:id,name,email', 'organization:id,name'])
            ->orderByDesc('ts')
            ->orderByDesc('id');

        if ($action = $request->query('action')) {
            $query->where('action', $action);
        }

        if ($org = $request->query('org')) {
            $query->where('organization_id', $org);
        }

        if ($actor = trim((string) $request->query('actor'))) {
            $query->whereIn('actor_id', User::where('email', 'ILIKE', "%{$actor}%")
                ->orWhere('name', 'ILIKE', "%{$actor}%")
                ->select('id'));
        }

        if ($from = $this->date($request->query('from'))) {
            $query->where('ts', '>=', $from);
        }
        if ($to = $this->date($request->query('to'))) {
            // Exclusive upper bound on the NEXT day = inclusive of the whole "to" day.
            $query->where('ts', '<', Carbon::parse($to)->addDay()->toDateString());
        }

        return view('admin.audit.index', [
            'entries' => $query->paginate(50)->withQueryString(),
            'actions' => AuditLog::select('action')->distinct()->orderBy('action')->pluck('action'),
            'organizations' => Organization::orderBy('name')->get(['id', 'name']),
            'filters' => [
                'action' => $request->query('action'),
                'org' => $request->query('org'),
                'actor' => $request->query('actor'),
                'from' => $request->query('from'),
                'to' => $request->query('to'),
            ],
        ]);
    }

    /** A valid Y-m-d date string or null -- garbage input must not become a query error. */
    private function date(?string $value): ?string
    {
        return ($value && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) ? $value : null;
    }
}

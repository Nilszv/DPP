<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Passport;
use App\Models\Scopes\OrganizationScope;
use App\Services\QrService;
use Illuminate\Http\Request;

/**
 * Platform-wide passport / QR browser. Spans all tenants (org scope removed explicitly),
 * paginated so it never loads thousands at once, with filtering by organization + status
 * and search by public id / GTIN / serial / product name.
 */
class AdminPassportController extends Controller
{
    public function index(Request $request)
    {
        $query = Passport::withoutGlobalScope(OrganizationScope::class)
            ->with(['product:id,name', 'organization:id,name'])
            ->latest();

        if ($org = $request->query('org')) {
            $query->where('organization_id', $org);
        }

        if (in_array($request->query('status'), ['draft', 'published', 'archived'], true)) {
            $query->where('status', $request->query('status'));
        }

        if ($q = trim((string) $request->query('q'))) {
            $query->where(function ($w) use ($q) {
                $w->whereRaw('public_id::text ILIKE ?', ["%{$q}%"])
                    ->orWhere('gtin', 'ILIKE', "%{$q}%")
                    ->orWhere('serial', 'ILIKE', "%{$q}%")
                    ->orWhereHas('product', fn ($p) => $p->where('name', 'ILIKE', "%{$q}%"));
            });
        }

        return view('admin.passports.index', [
            'passports' => $query->paginate(20)->withQueryString(),
            'organizations' => Organization::orderBy('name')->get(['id', 'name']),
            'filters' => [
                'org' => $request->query('org'),
                'status' => $request->query('status'),
                'q' => $request->query('q'),
            ],
        ]);
    }

    public function qr(string $passport, QrService $qr)
    {
        $model = Passport::withoutGlobalScope(OrganizationScope::class)->findOrFail($passport);

        return response($qr->svg($model->resolverUrl(), 200), 200, [
            'Content-Type' => 'image/svg+xml',
        ]);
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Admin CRUD for the DB-driven plan catalogue (prices, quotas, custom plans). */
class AdminPlanController extends Controller
{
    public function index()
    {
        return view('admin.plans.index', ['plans' => Plan::orderBy('sort')->get()]);
    }

    public function create()
    {
        return view('admin.plans.form', ['plan' => new Plan(['is_public' => false, 'active' => true])]);
    }

    public function store(Request $request)
    {
        $plan = Plan::create($this->validated($request));

        return redirect()->route('admin.plans.index')->with('status', "Created plan {$plan->name}.");
    }

    public function edit(Plan $plan)
    {
        return view('admin.plans.form', compact('plan'));
    }

    public function update(Request $request, Plan $plan)
    {
        $plan->update($this->validated($request, $plan));

        return redirect()->route('admin.plans.index')->with('status', "Updated plan {$plan->name}.");
    }

    private function validated(Request $request, ?Plan $plan = null): array
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'alpha_dash', 'max:50', Rule::unique('plans', 'key')->ignore($plan?->id)],
            'name' => ['required', 'string', 'max:100'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'interval' => ['nullable', 'in:month,year,custom'],
            'published_quota' => ['nullable', 'integer', 'min:0'],   // null = unlimited
            'sort' => ['nullable', 'integer'],
        ]);

        $data['is_public'] = $request->boolean('is_public');
        $data['active'] = $request->boolean('active');
        $data['sort'] ??= 0;

        return $data;
    }
}

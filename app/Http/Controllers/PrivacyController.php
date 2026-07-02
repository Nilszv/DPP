<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\LegalAcceptance;
use App\Services\AccountEraser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Self-service GDPR rights: data export (Art. 15/20) and account erasure (Art. 17). Lives
 * behind plain 'auth' (no org context / onboarded / active gates) -- a suspended or
 * half-onboarded user has exactly the same rights.
 */
class PrivacyController extends Controller
{
    public function show(Request $request, AccountEraser $eraser)
    {
        return view('app.privacy', [
            'user' => $request->user(),
            'blocker' => $eraser->blocker($request->user()),
            'impersonated' => $request->session()->has('impersonate.original_admin_id'),
        ]);
    }

    /** Everything the platform holds about this PERSON, as a portable JSON download. */
    public function export(Request $request)
    {
        $user = $request->user();

        AuditLog::record(action: 'gdpr.export', target: $user->id, meta: []);

        $export = [
            'generated_at' => now()->toIso8601String(),
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
                'created_at' => $user->created_at?->toIso8601String(),
                'two_factor_enabled' => $user->hasTwoFactorConfirmed(),
                'suspended_at' => $user->suspended_at?->toIso8601String(),
            ],
            'organizations' => $user->organizations()->get()->map(fn ($org) => [
                'name' => $org->name,
                'role' => $org->pivot->role,
                'joined_at' => $org->pivot->created_at,
            ])->values(),
            'legal_acceptances' => LegalAcceptance::where('user_id', $user->id)->get()
                ->map(fn ($a) => [
                    'document_key' => $a->document_key,
                    'document_version' => $a->document_version,
                    'accepted_at' => $a->accepted_at?->toIso8601String(),
                ])->values(),
            'invitations_received' => DB::table('invitations')->where('email', $user->email)
                ->get(['role', 'created_at', 'accepted_at', 'expires_at']),
            'invitations_sent' => DB::table('invitations')->where('invited_by', $user->id)
                ->get(['email', 'role', 'created_at', 'accepted_at']),
            'audit_entries_as_actor' => DB::table('audit_log')->where('actor_id', $user->id)
                ->orderByDesc('ts')->limit(1000)->get(['action', 'target', 'ts']),
            // Rows where this person is the SUBJECT (e.g. an admin impersonated them): also
            // their personal data under Art. 15. Only action + timestamp are disclosed --
            // meta and actor would expose third parties (like the acting admin's identity),
            // which Art. 15(4) requires balancing away.
            'audit_entries_about_you' => DB::table('audit_log')
                ->where(function ($q) use ($user) {
                    $q->where('target', $user->id)
                        ->orWhereRaw('strpos(meta::text, ?) > 0', [$user->email]);
                })
                ->where(fn ($q) => $q->whereNull('actor_id')->orWhere('actor_id', '!=', $user->id))
                ->orderByDesc('ts')->limit(1000)->get(['action', 'ts']),
        ];

        return response()->json($export, 200, [
            'Content-Disposition' => 'attachment; filename="dpp-personal-data-export.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function erase(Request $request, AccountEraser $eraser)
    {
        // An impersonating admin acts AS the user in this session; letting that session
        // destroy the account would make impersonation an unaudited erasure lever.
        if ($request->session()->has('impersonate.original_admin_id')) {
            return back()->with('error', 'Accounts cannot be deleted from an impersonation session.');
        }

        $request->validate(['confirm_email' => ['required', 'string']]);
        if (strtolower(trim($request->input('confirm_email'))) !== strtolower($request->user()->email)) {
            return back()->withErrors(['confirm_email' => 'Type your account email exactly to confirm deletion.']);
        }

        if ($blocker = $eraser->blocker($request->user())) {
            return back()->with('error', $blocker);
        }

        $eraser->erase($request->user(), initiatedBy: 'self');

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Your account and personal data have been deleted.');
    }
}

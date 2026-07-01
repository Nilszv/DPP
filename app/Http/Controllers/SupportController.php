<?php

namespace App\Http\Controllers;

use App\Mail\SupportRequestMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * In-app support page. A suspended account (e.g. blocked for duplicate registration) is
 * gated here by the 'not.suspended' middleware; any authenticated user may also use it to
 * reach support. The form (phone, email, company, message) is delivered to the support inbox.
 */
class SupportController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        $org = $user->organizations()->orderBy('organizations.created_at')->first();

        return view('app.support', [
            'user' => $user,
            'suspended' => $user->isSuspended(),
            'companyName' => $org?->legal_name ?? $org?->name ?? '',
            'phone' => $org?->contact_phone ?? '',
        ]);
    }

    public function send(Request $request)
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $user = $request->user();

        Mail::to(config('dpp.support_email'))->send(new SupportRequestMail(
            user: $user,
            phone: $data['phone'],
            email: $data['email'],
            companyName: $data['company_name'],
            body: $data['message'],
            // Admin-only context: the reason this account was flagged/suspended, if any.
            adminReason: $user->suspension_reason,
        ));

        return back()->with('status', 'Thanks. Your message has been sent to our support team and we will be in touch.');
    }
}

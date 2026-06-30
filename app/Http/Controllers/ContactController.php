<?php

namespace App\Http\Controllers;

use App\Mail\ContactSalesMail;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/** Sends "Contact sales" messages (downgrade requests, custom plans) to the sales inbox. */
class ContactController extends Controller
{
    public function sendSales(Request $request)
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
            'interest' => ['nullable', 'string', 'max:100'],
        ]);

        $org = Organization::findOrFail(app('currentOrganizationId'));

        Mail::to(config('dpp.sales_email'))->send(new ContactSalesMail(
            organization: $org,
            user: $request->user(),
            interest: $data['interest'] ?? null,
            body: $data['message'],
        ));

        return back()->with('status', 'Thanks. Your message has been sent to our team and we will be in touch.');
    }
}

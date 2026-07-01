<?php

namespace App\Mail;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Admin/support alert sent when an email account is auto-suspended for repeatedly attempting
 * to register a duplicate of an existing organization. The body carries the admin-only
 * reason (which existing org was matched, and the attempt count).
 */
class DuplicateRegistrationAlert extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public Organization $matchedOrganization,
        public string $reason,
        public int $attempts,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Account suspended (duplicate registration): '.$this->user->email,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.duplicate-registration-alert');
    }
}

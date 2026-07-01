<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A support request submitted from the in-app support page (phone, email, company, message).
 * Delivered to the support inbox. Includes an admin-only section with the account's
 * suspension reason (the error reason) so support has the context to resolve it.
 */
class SupportRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $phone,
        public string $email,
        public string $companyName,
        public string $body,
        public ?string $adminReason,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Support request: '.$this->companyName,
            replyTo: [new Address($this->email, $this->user->name ?? $this->companyName)],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.support-request');
    }
}

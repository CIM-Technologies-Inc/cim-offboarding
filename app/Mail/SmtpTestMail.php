<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A fixed, one-off test message for the SMTP Settings page's "Send Test
 * Email" action — not an admin-editable `EmailTemplate` (this is a
 * connectivity check, not a business notification, so it doesn't belong
 * in that system). Deliberately NOT queued (`ShouldQueue`), same as
 * `PasswordResetMail` and every other security/diagnostic-sensitive
 * email in this app — the admin needs to know synchronously, in the same
 * request, whether the send actually succeeded.
 */
class SmtpTestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'CIM OffBoarding - SMTP Test Email',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.smtp-test',
        );
    }

    /**
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}

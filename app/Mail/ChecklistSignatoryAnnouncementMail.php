<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// `ShouldQueue` — every `Mail::to(...)->send(...)` call site that uses this
// Mailable (the vast majority of emails in this app) stays completely
// unchanged: Laravel's own `Mailer::sendMailable()` checks
// `instanceof ShouldQueue` and automatically dispatches to the queue
// instead of sending synchronously, so `->send()` no longer blocks the
// HTTP request on a live SMTP round trip. Requires the queue to actually
// be processed — see the scheduled `queue:work --stop-when-empty` entry in
// `routes/console.php`, which reuses the same OS-level scheduler this app
// already depends on for its other scheduled commands, so this doesn't
// need a separately-managed persistent worker process.
class ChecklistSignatoryAnnouncementMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $emailSubject,
        public string $emailBody,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->emailSubject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.checklist-signatory-announcement',
            with: [
                'emailSubject' => $this->emailSubject,
                'emailBody' => $this->emailBody,
            ],
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
